<?php

declare(strict_types=1);

namespace Golem\Cli\Report;

/**
 * Passes each event on to several reporters.
 */
final class MultiReporter implements Reporter
{
    /** @var list<Reporter> */
    private readonly array $reporters;

    public function __construct(Reporter ...$reporters)
    {
        $this->reporters = array_values($reporters);
    }

    public function started(RunReport $report, int $count): void
    {
        foreach ($this->reporters as $reporter) {
            $reporter->started($report, $count);
        }
    }

    public function testFinished(TestResult $result): void
    {
        foreach ($this->reporters as $reporter) {
            $reporter->testFinished($result);
        }
    }

    public function finished(RunReport $report, string $serverLogTail): void
    {
        foreach ($this->reporters as $reporter) {
            $reporter->finished($report, $serverLogTail);
        }
    }
}
