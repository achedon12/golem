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

    /** @var array{commands: array<string, int>, listeners: array<string, int>, lines: array<string, array<int, int>>|null}|null lines: per file of src/, whether each executable line ran (1) or not (0); null without pcov or Xdebug */
    public ?array $coverage = null;

    /** @var list<array{command: string, owner: string, takenBy: string}> commands two plugins both want: owner lost it to takenBy */
    public array $conflicts = [];

    /** how many times each test ran (--repeat) */
    public int $repeat = 1;

    /** the seed of --random-order, to run the tests in the same order again */
    public ?int $seed = null;

    /**
     * Tests that passed in some runs and failed in others.
     *
     * @return array<string, array{result: TestResult, passed: int, failed: int}> by test id
     */
    public function flaky(): array
    {
        $tests = [];
        foreach ($this->results as $result) {
            $entry = $tests[$result->id()] ?? ['result' => $result, 'passed' => 0, 'failed' => 0];
            if ($result->status === TestResult::PASSED) {
                $entry['passed']++;
            } elseif ($result->isProblem()) {
                $entry['failed']++;
                if ($entry['result']->status === TestResult::PASSED) {
                    $entry['result'] = $result; // keep a failing run, to show why
                }
            }
            $tests[$result->id()] = $entry;
        }

        return array_filter($tests, static fn (array $entry) => $entry['passed'] > 0 && $entry['failed'] > 0);
    }

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
