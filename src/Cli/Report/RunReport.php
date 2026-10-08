<?php

declare(strict_types=1);

namespace Golem\Cli\Report;

/**
 * Everything known about a run once it is over.
 */
final class RunReport
{
    /** @var list<TestResult> */
    public array $results = [];

    public ?string $pocketmine = null;

    public ?string $plugin = null;

    public ?string $abortReason = null;

    public bool $crashed = false;

    public float $bootSeconds = 0.0;

    public float $totalSeconds = 0.0;

    public function count(string $status): int
    {
        return count(array_filter($this->results, static fn (TestResult $r) => $r->status === $status));
    }

    public function snapshotsWritten(): int
    {
        return array_sum(array_map(static fn (TestResult $r) => $r->snapshotsWritten, $this->results));
    }

    public function assertions(): int
    {
        return array_sum(array_map(static fn (TestResult $r) => $r->assertions, $this->results));
    }

    public function isSuccessful(): bool
    {
        return !$this->crashed
            && $this->abortReason === null
            && $this->count(TestResult::FAILED) === 0
            && $this->count(TestResult::ERRORED) === 0;
    }
}
