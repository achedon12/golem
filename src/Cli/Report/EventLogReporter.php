<?php

declare(strict_types=1);

namespace Golem\Cli\Report;

/**
 * Writes the run as JSON lines, one per event, for tools that follow it (golem ui):
 * started, one test per result, then finished with the summary and the coverage.
 */
final class EventLogReporter implements Reporter
{
    public function __construct(private readonly string $file)
    {
        $directory = dirname($file);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($file, '');
    }

    public function started(RunReport $report, int $count): void
    {
        $this->write(['type' => 'started', 'count' => $count, 'plugin' => $report->plugin, 'pocketmine' => $report->pocketmine, 'label' => $report->label]);
    }

    public function testFinished(TestResult $result): void
    {
        $this->write(['type' => 'test', 'name' => $result->name(), 'description' => $result->description(), 'shortClass' => $result->shortClass()] + get_object_vars($result));
    }

    public function finished(RunReport $report, string $serverLogTail): void
    {
        $this->write([
            'type' => 'finished',
            'successful' => $report->isSuccessful(),
            'crashed' => $report->crashed,
            'abortReason' => $report->abortReason,
            'counts' => [
                'passed' => $report->count(TestResult::PASSED),
                'failed' => $report->count(TestResult::FAILED),
                'errored' => $report->count(TestResult::ERRORED),
                'skipped' => $report->count(TestResult::SKIPPED),
            ],
            'assertions' => $report->assertions(),
            'seconds' => round($report->totalSeconds, 2),
            'bootSeconds' => round($report->bootSeconds, 2),
            'coverage' => $report->coverage,
            'serverLog' => $serverLogTail,
        ]);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function write(array $event): void
    {
        file_put_contents($this->file, json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n", FILE_APPEND);
    }
}
