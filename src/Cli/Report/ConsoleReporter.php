<?php

declare(strict_types=1);

namespace Golem\Cli\Report;

use Golem\Cli\Output;

/**
 * Prints results as they arrive, then a summary with every failure explained.
 */
final class ConsoleReporter implements Reporter
{
    private ?string $currentClass = null;

    public function __construct(
        private readonly Output $output,
        private readonly string $projectRoot,
    ) {
    }

    public function started(RunReport $report, int $count): void
    {
        $this->output->writeln(sprintf(
            '  <dim>%s · PocketMine-MP %s · %d test%s</>',
            Output::escape((string) $report->plugin),
            Output::escape((string) $report->pocketmine),
            $count,
            $count === 1 ? '' : 's',
        ));
        foreach ($report->conflicts as $conflict) {
            $this->output->writeln('  <yellow>! ' . Output::escape(self::conflict($conflict)) . '</>');
        }
    }

    public function testFinished(TestResult $result): void
    {
        // with --repeat, later runs only show when they go wrong
        if ($result->repetition > 1 && !$result->isProblem()) {
            return;
        }
        if ($result->class !== $this->currentClass) {
            $this->currentClass = $result->class;
            $this->output->writeln();
            $this->output->writeln('  <bold>' . Output::escape($result->shortClass()) . '</>');
        }

        $line = match ($result->status) {
            TestResult::PASSED => '  <green>✓</> <gray>%s</>',
            TestResult::SKIPPED => '  <yellow>-</> <gray>%s</> <yellow>skipped</>',
            default => '  <red>✗ %s</>',
        };
        $text = sprintf($line, Output::escape($result->description() . ($result->repetition > 1 ? " (run {$result->repetition})" : '')));
        if ($result->status === TestResult::SKIPPED && $result->message !== null && $result->message !== '') {
            $text .= ' <gray>· ' . Output::escape($result->message) . '</>';
        }
        if ($result->status !== TestResult::SKIPPED) {
            $text .= sprintf(' <dim>%s</>', $this->duration($result));
        }
        $this->output->writeln($text);
    }

    public function finished(RunReport $report, string $serverLogTail): void
    {
        foreach ($report->results as $result) {
            if ($result->isProblem()) {
                $this->failure($result);
            }
        }

        if ($report->abortReason !== null) {
            $this->output->writeln();
            $this->output->writeln('  <fail> ABORTED </> ' . Output::escape($report->abortReason));
            $this->serverLog($serverLogTail);
        } elseif ($report->crashed) {
            $this->output->writeln();
            $this->output->writeln('  <fail> CRASHED </> The server stopped before the tests were over. Here is what it logged:');
            $this->serverLog($serverLogTail);
        }

        $this->summary($report);
    }

    private function failure(TestResult $result): void
    {
        $this->output->writeln();
        $badge = $result->status === TestResult::ERRORED ? '<fail> ERROR </>' : '<fail> FAILED </>';
        $this->output->writeln(sprintf(
            '  %s <bold>%s</> <gray>›</> %s',
            $badge,
            Output::escape($result->shortClass()),
            Output::escape($result->description()),
        ));

        $message = $result->message ?? '';
        if ($result->status === TestResult::ERRORED && $result->exception !== null) {
            $message = $result->exception . ($message !== '' ? ": $message" : '');
        }
        foreach (explode("\n", $message) as $line) {
            $this->output->writeln('  ' . Output::escape($line));
        }

        if ($result->expected !== null || $result->actual !== null) {
            $this->output->writeln();
            $this->comparison('<green>expected</>', $result->expected);
            $this->comparison('<red>actual  </>', $result->actual);
        }

        if ($result->failureFile !== null && $result->failureLine !== null) {
            $this->output->writeln();
            $this->output->writeln(sprintf('  <gray>at</> <cyan>%s:%d</>', Output::escape($this->relative($result->failureFile)), $result->failureLine));
            $this->snippet($result->failureFile, $result->failureLine);
        }

        $trace = array_filter($result->trace, fn (string $frame) => !str_starts_with($frame, (string) $result->failureFile));
        if ($result->status === TestResult::ERRORED && $trace !== []) {
            $this->output->writeln();
            foreach (array_slice($trace, 0, 6) as $frame) {
                $this->output->writeln('  <gray>' . Output::escape($this->relative($frame)) . '</>');
            }
        }
    }

    private function comparison(string $label, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        $lines = explode("\n", $value);
        $this->output->writeln('  ' . $label . '  ' . Output::escape(array_shift($lines)));
        foreach ($lines as $line) {
            $this->output->writeln('            ' . Output::escape($line));
        }
    }

    private function snippet(string $file, int $line): void
    {
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }
        $from = max(1, $line - 2);
        $to = min(count($lines), $line + 2);
        $width = strlen((string) $to);
        for ($number = $from; $number <= $to; $number++) {
            $code = Output::escape(rtrim(str_replace("\t", '    ', $lines[$number - 1])));
            if ($number === $line) {
                $this->output->writeln(sprintf('  <red>➜ %s</> <gray>│</> %s', str_pad((string) $number, $width, ' ', STR_PAD_LEFT), $code));
            } else {
                $this->output->writeln(sprintf('  <gray>  %s │</> <gray>%s</>', str_pad((string) $number, $width, ' ', STR_PAD_LEFT), $code));
            }
        }
    }

    private function serverLog(string $tail): void
    {
        if ($tail === '') {
            return;
        }
        $this->output->writeln();
        foreach (explode("\n", $tail) as $line) {
            $this->output->writeln('  <gray>│</> ' . Output::escape($line));
        }
    }

    private function summary(RunReport $report): void
    {
        $parts = [];
        foreach ([
            TestResult::FAILED => '<red>%d failed</>',
            TestResult::ERRORED => '<red>%d errored</>',
            TestResult::SKIPPED => '<yellow>%d skipped</>',
            TestResult::PASSED => '<green>%d passed</>',
        ] as $status => $format) {
            $count = $report->count($status);
            if ($count > 0) {
                $parts[] = sprintf($format, $count);
            }
        }
        if ($parts === []) {
            $parts[] = '<yellow>no tests ran</>';
        }

        $this->output->writeln();
        $this->output->writeln(sprintf(
            '  <gray>Tests:</>    %s <gray>(%d assertion%s)</>',
            implode('<gray>,</> ', $parts),
            $report->assertions(),
            $report->assertions() === 1 ? '' : 's',
        ));
        if ($report->coverage !== null) {
            $this->coverage($report->coverage);
        }
        $flaky = $report->flaky();
        if ($flaky !== []) {
            $this->output->writeln(sprintf('  <gray>Flaky:</>     <yellow>%d test%s passed in some runs and failed in others</>', count($flaky), count($flaky) === 1 ? '' : 's'));
            foreach ($flaky as $entry) {
                $this->output->writeln(sprintf(
                    '             <yellow>%s › %s</> <gray>· failed %d of %d runs</>',
                    Output::escape($entry['result']->shortClass()),
                    Output::escape($entry['result']->description()),
                    $entry['failed'],
                    $entry['passed'] + $entry['failed'],
                ));
            }
        }
        if ($report->seed !== null) {
            $this->output->writeln(sprintf('  <gray>Order:</>     random, seed %d <gray>(same order again: --random-order=%d)</>', $report->seed, $report->seed));
        }
        if ($report->snapshotsWritten() > 0) {
            $this->output->writeln(sprintf('  <gray>Snapshots:</> <yellow>%d written</> <gray>(commit them)</>', $report->snapshotsWritten()));
        }
        $this->output->writeln(sprintf(
            '  <gray>Duration:</> %.2fs%s',
            $report->totalSeconds,
            $report->bootSeconds > 0 ? sprintf(' <gray>(server boot %.2fs)</>', $report->bootSeconds) : '',
        ));
        $this->output->writeln();
    }

    /**
     * @param array{commands: array<string, int>, listeners: array<string, int>, lines: array<string, array<int, int>>|null} $coverage
     */
    private function coverage(array $coverage): void
    {
        $line = [];
        foreach (['commands' => 'commands', 'listeners' => 'listeners'] as $key => $label) {
            $all = $coverage[$key];
            $covered = count(array_filter($all, static fn (int $count) => $count > 0));
            $color = $covered === count($all) ? 'green' : 'yellow';
            $line[] = sprintf('%s <%s>%d/%d</>', $label, $color, $covered, count($all));
        }
        $lines = $coverage['lines'];
        if ($lines !== null && $lines !== []) {
            $total = array_sum(array_map('count', $lines));
            $covered = array_sum(array_map(static fn (array $file) => count(array_filter($file)), $lines));
            $line[] = sprintf('lines <%s>%.1f%%</> <gray>(%d/%d)</>', $covered === $total ? 'green' : 'yellow', $total > 0 ? $covered / $total * 100 : 100, $covered, $total);
        }
        $this->output->writeln('  <gray>Coverage:</> ' . implode(' <gray>·</> ', $line));
        if ($lines !== null) {
            $this->uncoveredLines($lines);
        }

        $missing = [
            'never run' => array_map(static fn (string $name) => '/' . $name, array_keys(array_filter($coverage['commands'], static fn (int $count) => $count === 0))),
            'never called' => array_keys(array_filter($coverage['listeners'], static fn (int $count) => $count === 0)),
        ];
        foreach ($missing as $label => $names) {
            if ($names !== []) {
                $this->output->writeln(sprintf('            <gray>%s:</> %s', $label, Output::escape(implode(', ', $names))));
            }
        }
    }

    /**
     * The files with lines the tests never ran, least covered first.
     *
     * @param array<string, array<int, int>> $lines
     */
    private function uncoveredLines(array $lines): void
    {
        $files = [];
        foreach ($lines as $file => $fileLines) {
            $missed = array_keys(array_filter($fileLines, static fn (int $ran) => $ran === 0));
            if ($missed !== []) {
                $files[$file] = [count($fileLines) > 0 ? 1 - count($missed) / count($fileLines) : 1, $missed];
            }
        }
        uasort($files, static fn (array $a, array $b) => $a[0] <=> $b[0]);

        foreach (array_slice($files, 0, 8, true) as $file => [$ratio, $missed]) {
            $this->output->writeln(sprintf(
                '            <cyan>src/%s</> <yellow>%d%%</> <gray>· not run: %s</>',
                Output::escape($file),
                (int) floor($ratio * 100),
                Output::escape(self::ranges($missed)),
            ));
        }
        if (count($files) > 8) {
            $this->output->writeln(sprintf('            <gray>and %d more file(s): see --coverage-clover</>', count($files) - 8));
        }
    }

    /**
     * [3, 4, 5, 9] becomes "3-5, 9".
     *
     * @param list<int> $numbers sorted
     */
    private static function ranges(array $numbers): string
    {
        $ranges = [];
        $start = $previous = null;
        foreach ([...$numbers, null] as $number) {
            if ($start !== null && $number === $previous + 1) {
                $previous = $number;
                continue;
            }
            if ($start !== null) {
                $ranges[] = $start === $previous ? (string) $start : "$start-$previous";
            }
            $start = $previous = $number;
        }

        return implode(', ', $ranges);
    }

    /**
     * @param array{command: string, owner: string, takenBy: string} $conflict
     */
    public static function conflict(array $conflict): string
    {
        return sprintf(
            '/%s of %s is taken by %s: typing /%s runs %s\'s, the other is only /%s:%s',
            $conflict['command'],
            $conflict['owner'],
            $conflict['takenBy'],
            $conflict['command'],
            $conflict['takenBy'],
            strtolower($conflict['owner']),
            $conflict['command'],
        );
    }

    private function duration(TestResult $result): string
    {
        $time = $result->seconds >= 1 ? sprintf('%.2fs', $result->seconds) : sprintf('%dms', (int) round($result->seconds * 1000));

        return sprintf('%s · %d tick%s', $time, $result->ticks, $result->ticks === 1 ? '' : 's');
    }

    private function relative(string $path): string
    {
        $root = rtrim($this->projectRoot, '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
