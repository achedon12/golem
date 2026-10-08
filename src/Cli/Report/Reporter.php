<?php

declare(strict_types=1);

namespace Golem\Cli\Report;

/**
 * Follows a run as its events arrive.
 */
interface Reporter
{
    public function started(RunReport $report, int $count): void;

    public function testFinished(TestResult $result): void;

    public function finished(RunReport $report, string $serverLogTail): void;
}
