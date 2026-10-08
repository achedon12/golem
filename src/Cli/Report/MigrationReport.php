<?php

declare(strict_types=1);

namespace Golem\Cli\Report;

use Golem\Cli\Output;

/**
 * What changes when the same tests run on two servers: the answer to "what breaks if I
 * move to that fork?".
 */
final class MigrationReport
{
    /** @var list<array{name: string, from: ?TestResult, to: ?TestResult}> */
    private array $differences = [];

    private int $same = 0;

    public function __construct(
        private readonly RunReport $from,
        private readonly RunReport $to,
    ) {
        $fromResults = self::byName($from);
        $toResults = self::byName($to);
        foreach (array_unique([...array_keys($fromResults), ...array_keys($toResults)]) as $name) {
            $a = $fromResults[$name] ?? null;
            $b = $toResults[$name] ?? null;
            if ($a !== null && $b !== null && self::outcome($a) === self::outcome($b)) {
                $this->same++;
                continue;
            }
            $this->differences[] = ['name' => $name, 'from' => $a, 'to' => $b];
        }
    }

    /**
     * Tests that pass on the first server and do not on the second.
     */
    public function regressions(): int
    {
        return count(array_filter($this->differences, static fn (array $d) => $d['from']?->status === TestResult::PASSED && $d['to']?->status !== TestResult::PASSED));
    }

    public function render(Output $output): void
    {
        $output->writeln();
        $output->writeln(sprintf(
            '  <bold>Migration report</> <gray>%s →</> <bold>%s</>',
            Output::escape((string) $this->from->label),
            Output::escape((string) $this->to->label),
        ));
        foreach ([$this->from, $this->to] as $run) {
            if ($run->crashed || $run->abortReason !== null) {
                $output->writeln(sprintf('  <fail> ! </> The run on %s did not complete: %s', Output::escape((string) $run->label), Output::escape($run->abortReason ?? 'the server crashed')));
            }
        }
        $output->writeln();

        if ($this->differences === []) {
            $output->writeln(sprintf('  <green>✓</> All %d tests behave the same on both servers.', $this->same));
            $output->writeln();

            return;
        }

        // Tests that changed the same way for the same reason are one problem, not many.
        foreach ($this->groups() as $group) {
            $first = $group[0];
            $count = count($group);
            $output->writeln(sprintf(
                '  %s %s <gray>→</> %s  <bold>%d test%s</>',
                $first['from']?->status === TestResult::PASSED ? '<red>✗</>' : '<green>✓</>',
                self::badge($first['from']),
                self::badge($first['to']),
                $count,
                $count === 1 ? '' : 's',
            ));
            foreach (['from', 'to'] as $side) {
                $result = $first[$side];
                if ($result !== null && $result->isProblem() && $result->message !== null) {
                    $label = $side === 'from' ? $this->from->label : $this->to->label;
                    $output->writeln(sprintf('    <gray>on %s:</> %s', Output::escape((string) $label), Output::escape(self::firstLine($result))));
                    if ($result->expected !== null || $result->actual !== null) {
                        $output->writeln(sprintf('    <gray>expected</> %s <gray>actual</> %s', Output::escape(self::oneLine($result->expected)), Output::escape(self::oneLine($result->actual))));
                    }
                }
            }
            $names = array_map(static fn (array $d) => $d['name'], $group);
            foreach (array_slice($names, 0, 8) as $name) {
                $output->writeln('    <gray>·</> ' . Output::escape($name));
            }
            if ($count > 8) {
                $output->writeln(sprintf('    <gray>· and %d more</>', $count - 8));
            }
            $output->writeln();
        }

        $output->writeln(sprintf(
            '  <gray>Tests:</> %d behave the same, <red>%d break</>, <green>%d get fixed</>, %d other changes',
            $this->same,
            $this->regressions(),
            $this->fixes(),
            count($this->differences) - $this->regressions() - $this->fixes(),
        ));
        $output->writeln();
    }

    /**
     * The same report as a GitHub-flavoured markdown table, for the job summary.
     */
    public function markdown(): string
    {
        $lines = [
            sprintf('## Migration report: %s → %s', $this->from->label, $this->to->label),
            '',
        ];
        if ($this->differences === []) {
            $lines[] = sprintf('All %d tests behave the same on both servers. ✅', $this->same);

            return implode("\n", $lines) . "\n";
        }
        $lines[] = sprintf('| Test | %s | %s | Details |', $this->from->label, $this->to->label);
        $lines[] = '| --- | --- | --- | --- |';
        foreach ($this->differences as $difference) {
            $details = $difference['to']->message ?? $difference['from']->message ?? '';
            $lines[] = sprintf(
                '| %s | %s | %s | %s |',
                $difference['name'],
                $difference['from']->status ?? 'missing',
                $difference['to']->status ?? 'missing',
                str_replace(['|', "\n"], ['\\|', ' '], $details),
            );
        }
        $lines[] = '';
        $lines[] = sprintf('%d behave the same, **%d break**, %d get fixed.', $this->same, $this->regressions(), $this->fixes());

        return implode("\n", $lines) . "\n";
    }

    /**
     * Differences grouped by how the outcome changed and why.
     *
     * @return list<non-empty-list<array{name: string, from: ?TestResult, to: ?TestResult}>>
     */
    private function groups(): array
    {
        $groups = [];
        foreach ($this->differences as $difference) {
            $key = implode('|', [
                $difference['from']->status ?? 'missing',
                $difference['to']->status ?? 'missing',
                $difference['from'] !== null ? self::firstLine($difference['from']) : '',
                $difference['to'] !== null ? self::firstLine($difference['to']) : '',
            ]);
            $groups[$key][] = $difference;
        }
        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b) => count($b) <=> count($a));

        return $groups;
    }

    private static function firstLine(TestResult $result): string
    {
        if (!$result->isProblem() || $result->message === null) {
            return '';
        }
        $line = strtok($result->message, "\n");
        $line = $line === false ? $result->message : $line;

        return $result->exception !== null && $result->status === TestResult::ERRORED
            ? substr($result->exception, (int) strrpos('\\' . $result->exception, '\\')) . ': ' . $line
            : $line;
    }

    private function fixes(): int
    {
        return count(array_filter($this->differences, static fn (array $d) => $d['from'] !== null && $d['from']->status !== TestResult::PASSED && $d['to']?->status === TestResult::PASSED));
    }

    /**
     * @return array<string, TestResult>
     */
    private static function byName(RunReport $report): array
    {
        $results = [];
        foreach ($report->results as $result) {
            $results[$result->shortClass() . ' › ' . $result->description()] = $result;
        }

        return $results;
    }

    private static function outcome(TestResult $result): string
    {
        return $result->isProblem() ? 'broken' : $result->status;
    }

    private static function badge(?TestResult $result): string
    {
        return match ($result?->status) {
            TestResult::PASSED => '<green>passed</>',
            TestResult::SKIPPED => '<yellow>skipped</>',
            TestResult::FAILED => '<red>failed</>',
            TestResult::ERRORED => '<red>errored</>',
            default => '<gray>missing</>',
        };
    }

    private static function oneLine(?string $value): string
    {
        $value = preg_replace('/\s+/', ' ', (string) $value) ?? '';

        return mb_strlen($value) > 60 ? mb_substr($value, 0, 57) . '…' : $value;
    }
}
