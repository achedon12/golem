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

    /** the server the run used, as given on the command line ("5.44.3", "5.118.8 (owner/fork)") */
    public ?string $label = null;

    public ?string $plugin = null;

    public ?string $abortReason = null;

    public bool $crashed = false;

    public float $bootSeconds = 0.0;

    public float $totalSeconds = 0.0;

    /** @var array{commands: array<string, int>, listeners: array<string, int>}|null */
    public ?array $coverage = null;

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
