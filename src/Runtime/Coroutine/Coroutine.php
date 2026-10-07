<?php

declare(strict_types=1);

namespace Golem\Runtime\Coroutine;

use pocketmine\promise\Promise;

/**
 * Drives a generator across server ticks.
 *
 * Each yielded {@see Deferred} (or PocketMine {@see Promise}) suspends the generator
 * until it settles; the result is sent back in, a failure is thrown back in.
 */
final class Coroutine
{
    private bool $finished = false;

    /** @var array{file: string, line: int}|null */
    private ?array $suspendedAt = null;

    /** @var Deferred<mixed> */
    private Deferred $completion;

    /**
     * @param \Generator<mixed, mixed, mixed, mixed> $generator
     */
    private function __construct(private readonly \Generator $generator)
    {
        $this->completion = new Deferred();
    }

    /**
     * Runs the generator until its first suspension point.
     *
     * @param \Generator<mixed, mixed, mixed, mixed> $generator
     */
    public static function run(\Generator $generator): self
    {
        $coroutine = new self($generator);
        $coroutine->step(static fn () => $generator->current());

        return $coroutine;
    }

    /**
     * @return Deferred<mixed> settles with the generator's return value
     */
    public function completion(): Deferred
    {
        return $this->completion;
    }

    /**
     * Where the generator last paused, i.e. the `yield` a test was stuck on.
     *
     * @return array{file: string, line: int}|null
     */
    public function suspendedAt(): ?array
    {
        return $this->suspendedAt;
    }

    /**
     * Abandons the generator: whatever it is waiting for, it will never be resumed.
     */
    public function cancel(): void
    {
        $this->finished = true;
    }

    /**
     * @param \Closure(): mixed $advance resumes the generator and returns what it yields next
     */
    private function step(\Closure $advance): void
    {
        if ($this->finished) {
            return;
        }

        try {
            $yielded = $advance();
        } catch (\Throwable $e) {
            $this->finish($e);

            return;
        }

        if (!$this->generator->valid()) {
            $this->finish(null);

            return;
        }

        $this->await($yielded);
    }

    private function await(mixed $yielded): void
    {
        $executing = (new \ReflectionGenerator($this->generator))->getExecutingGenerator();
        $reflection = new \ReflectionGenerator($executing);
        $this->suspendedAt = ['file' => $reflection->getExecutingFile(), 'line' => $reflection->getExecutingLine()];

        $resume = fn (mixed $value) => $this->step(fn () => $this->generator->send($value));
        $fail = fn (\Throwable $e) => $this->step(fn () => $this->generator->throw($e));

        if ($yielded instanceof Deferred) {
            $yielded->then($resume, $fail);

            return;
        }

        if ($yielded instanceof Promise) {
            $yielded->onCompletion($resume, static fn () => $fail(new \RuntimeException('The awaited promise was rejected')));

            return;
        }

        $fail(new \LogicException(sprintf(
            'A test can only yield %s or %s, got %s. Did you mean `yield $this->wait(...)`?',
            Deferred::class,
            Promise::class,
            get_debug_type($yielded),
        )));
    }

    private function finish(?\Throwable $error): void
    {
        $this->finished = true;
        if ($error !== null) {
            $this->completion->reject($error);
        } else {
            $this->completion->resolve($this->generator->getReturn());
        }
    }
}
