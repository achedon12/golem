<?php

declare(strict_types=1);

namespace Golem\Runtime;

use Golem\Assert\AssertionFailed;
use Golem\Runtime\Coroutine\Coroutine;
use Golem\TestCase;
use Golem\TestSkipped;
use Golem\WaitTimedOut;
use pocketmine\scheduler\ClosureTask;

/**
 * Runs tests one after the other, each spread over as many ticks as it needs.
 *
 * @internal
 */
final class TestRunner
{
    private const PASSED = 'passed';
    private const FAILED = 'failed';
    private const ERRORED = 'errored';
    private const SKIPPED = 'skipped';

    /** @var list<TestDefinition> */
    private array $queue;

    /** identifies the running test, so late callbacks from an abandoned one are ignored */
    private int $generation = 0;

    /**
     * @param list<TestDefinition> $tests
     * @param \Closure(): void $onFinished
     */
    public function __construct(
        private readonly Runtime $runtime,
        array $tests,
        private readonly EventLog $events,
        private readonly string $testsDirectory,
        private readonly \Closure $onFinished,
    ) {
        $this->queue = $tests;
    }

    public function start(): void
    {
        $this->next();
    }

    private function next(): void
    {
        $test = array_shift($this->queue);
        if ($test === null) {
            ($this->onFinished)();

            return;
        }

        if ($test->skipReason !== null) {
            $this->report($test, self::SKIPPED, 0.0, 0, 0, ['message' => $test->skipReason]);
            $this->next();

            return;
        }

        $this->run($test);
    }

    private function run(TestDefinition $test): void
    {
        $generation = ++$this->generation;
        $startedAt = hrtime(true);
        $startTick = $this->runtime->plugin->getServer()->getTick();

        try {
            $instance = new ($test->class)();
            if ($test->worldTemplate !== null) {
                $this->runtime->worlds->fromTemplate($test->worldTemplate);
            } elseif ($test->freshWorld) {
                $this->runtime->worlds->fresh();
            }
        } catch (\Throwable $e) {
            try {
                $this->runtime->worlds->discard();
            } catch (\Throwable) {
                // the original error is the one worth reporting
            }
            $this->report($test, self::ERRORED, 0.0, 0, 0, $this->describe($e, null));
            $this->scheduleNext();

            return;
        }

        $run = new RunningTest(
            $test,
            $instance,
            Coroutine::run(self::body($instance, $test->method, $test->arguments)),
            $generation,
            $startedAt,
            $startTick,
        );
        $run->timeout = $this->runtime->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(fn () => $this->finish($run, new WaitTimedOut(sprintf(
            'The test did not finish within %d ticks. Raise the limit with #[Timeout(ticks)] if it really needs more time.',
            $test->timeoutTicks,
        )))), $test->timeoutTicks);

        $run->coroutine->completion()->then(
            fn () => $this->finish($run, null),
            fn (\Throwable $e) => $this->finish($run, $e),
        );
    }

    private function finish(RunningTest $run, ?\Throwable $error): void
    {
        if ($run->generation !== $this->generation) {
            return;
        }
        $this->generation++;
        $run->timeout?->cancel();
        $run->coroutine->cancel();

        try {
            $run->instance->runTearDown();
        } catch (\Throwable $e) {
            $error ??= $e;
        }
        $this->runtime->golems->despawnAll();
        try {
            $this->runtime->worlds->discard();
        } catch (\Throwable $e) {
            $error ??= $e;
        }

        $stuckAt = $run->coroutine->suspendedAt();
        [$status, $details] = match (true) {
            $error === null => [self::PASSED, []],
            $error instanceof TestSkipped => [self::SKIPPED, ['message' => $error->getMessage()]],
            $error instanceof AssertionFailed, $error instanceof WaitTimedOut => [self::FAILED, $this->describe($error, $stuckAt)],
            default => [self::ERRORED, $this->describe($error, $stuckAt)],
        };
        $this->report(
            $run->definition,
            $status,
            (hrtime(true) - $run->startedAt) / 1e9,
            $this->runtime->plugin->getServer()->getTick() - $run->startTick,
            $run->instance->assertionCount(),
            $details,
        );
        $this->scheduleNext();
    }

    /**
     * setUp() and the test method as a single coroutine.
     *
     * @param list<mixed> $arguments
     * @return \Generator<mixed, mixed, mixed, mixed>
     */
    private static function body(TestCase $instance, string $method, array $arguments): \Generator
    {
        $setUp = $instance->runSetUp();
        if ($setUp !== null) {
            yield from $setUp;
        }

        $result = $instance->{$method}(...$arguments);
        if ($result instanceof \Generator) {
            yield from $result;
        }
    }

    /**
     * Gives the server one tick to process disconnections before the next test.
     */
    private function scheduleNext(): void
    {
        $this->runtime->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(fn () => $this->next()), 1);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function report(TestDefinition $test, string $status, float $seconds, int $ticks, int $assertions, array $details): void
    {
        $this->events->write('test', [
            'class' => $test->class,
            'method' => $test->method,
            'data' => $test->dataName,
            'file' => $test->file,
            'line' => $test->line,
            'status' => $status,
            'seconds' => round($seconds, 4),
            'ticks' => $ticks,
            'assertions' => $assertions,
        ] + $details);
    }

    /**
     * @param array{file: string, line: int}|null $stuckAt the yield the test was paused on
     * @return array<string, mixed>
     */
    private function describe(\Throwable $e, ?array $stuckAt): array
    {
        $details = [
            'message' => $e->getMessage(),
            'exception' => $e::class,
        ];
        if ($e instanceof AssertionFailed) {
            $details['expected'] = $e->expected;
            $details['actual'] = $e->actual;
        }

        $location = $this->locate($e) ?? $stuckAt;
        if ($location !== null) {
            $details['location'] = $location;
        }

        if (!$e instanceof AssertionFailed) {
            $details['trace'] = $this->trace($e);
        }

        return $details;
    }

    /**
     * The line of the test file that led to the failure.
     *
     * @return array{file: string, line: int}|null
     */
    private function locate(\Throwable $e): ?array
    {
        $frames = [['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()];
        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;
            if (is_string($file) && str_starts_with(str_replace('\\', '/', $file), $this->testsDirectory)) {
                return ['file' => $file, 'line' => (int) ($frame['line'] ?? 0)];
            }
        }

        return null;
    }

    /**
     * Stack frames outside of Golem and PocketMine, the ones worth reading.
     *
     * @return list<string>
     */
    private function trace(\Throwable $e): array
    {
        $golemSource = str_replace('\\', '/', dirname(__DIR__));
        $frames = [['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()];
        $lines = [];
        foreach ($frames as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            if ($file === '' || str_starts_with($file, 'phar://') || str_ends_with($file, '.phar') || str_starts_with($file, $golemSource)) {
                continue;
            }
            $lines[] = $file . ':' . ($frame['line'] ?? 0);
        }

        return array_values(array_unique($lines));
    }
}
