<?php

declare(strict_types=1);

namespace Golem\Cli\Report;

use Golem\Cli\Output;

/**
 * One character per test, for runs whose details are shown elsewhere (--compare).
 */
final class CompactReporter implements Reporter
{
    public function __construct(private readonly Output $output)
    {
    }

    public function started(RunReport $report, int $count): void
    {
        $this->output->write(sprintf('  <dim>%d tests</> ', $count));
    }

    public function testFinished(TestResult $result): void
    {
        $this->output->write(match ($result->status) {
            TestResult::PASSED => '<green>.</>',
            TestResult::SKIPPED => '<yellow>s</>',
            default => '<red>F</>',
        });
    }

    public function finished(RunReport $report, string $serverLogTail): void
    {
        $this->output->writeln();
        if ($report->abortReason !== null) {
            $this->output->writeln('  <fail> ABORTED </> ' . Output::escape($report->abortReason));
        } elseif ($report->crashed) {
            $this->output->writeln('  <fail> CRASHED </> The server stopped before the tests were over:');
            foreach (array_slice(explode("\n", trim($serverLogTail)), 0, 3) as $line) {
                // "[13:03:20.052] [Server thread/CRITICAL]: Could not load plugin…" keeps only the message
                $this->output->writeln('  <gray>│</> ' . Output::escape((string) preg_replace('/^\[[^\]]*\] \[[^\]]*\]: /', '', $line)));
            }
        }
        $this->output->writeln(sprintf(
            '  <green>%d passed</><gray>,</> <red>%d failed</><gray>,</> <yellow>%d skipped</> <gray>in %.1fs</>',
            $report->count(TestResult::PASSED),
            $report->count(TestResult::FAILED) + $report->count(TestResult::ERRORED),
            $report->count(TestResult::SKIPPED),
            $report->totalSeconds,
        ));
    }
}
