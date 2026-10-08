<?php

declare(strict_types=1);

namespace Golem\Cli\Report;

use Golem\Cli\Output;

/**
 * Prints results as they arrive, then a summary with every failure explained.
 */
final class ConsoleReporter
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
    }

    public function testFinished(TestResult $result): void
    {
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
        $text = sprintf($line, Output::escape($result->description()));
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
