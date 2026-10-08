<?php

declare(strict_types=1);

namespace Golem\Runtime\Coroutine;

/**
 * A value that will be available on a later tick.
 *
 * Yield it from a test to suspend the test until it settles:
 *
 *     $steve = yield $this->golem('Steve');
 *
 * @template T
 */
final class Deferred
{
    private const PENDING = 0;
    private const RESOLVED = 1;
    private const REJECTED = 2;

    private int $state = self::PENDING;

    /** @var T set once resolved */
    private mixed $value;

    private ?\Throwable $reason = null;

    /** @var list<array{\Closure(T): void, \Closure(\Throwable): void}> */
    private array $callbacks = [];

    /**
     * Settles once every deferred has: with their values in the same order, or with the
     * first failure.
     *
     * @template V
     * @param list<Deferred<V>> $deferreds
     * @return Deferred<list<V>>
     */
    public static function all(array $deferreds): self
    {
        /** @var Deferred<list<V>> $all */
        $all = new self();
        $values = [];
        $pending = count($deferreds);
        if ($pending === 0) {
            $all->resolve([]);

            return $all;
        }
        foreach ($deferreds as $index => $deferred) {
            $deferred->then(static function (mixed $value) use ($all, &$values, &$pending, $index, $deferreds): void {
                $values[$index] = $value;
                if (--$pending === 0) {
                    $ordered = [];
                    foreach (array_keys($deferreds) as $key) {
                        $ordered[] = $values[$key];
                    }
                    $all->resolve($ordered);
                }
            }, static fn (\Throwable $reason) => $all->reject($reason));
        }

        return $all;
    }

    /**
     * @param T $value
     */
    public function resolve(mixed $value = null): void
    {
        if ($this->state !== self::PENDING) {
            return;
        }
        $this->state = self::RESOLVED;
        $this->value = $value;
        $this->flush();
    }

    public function reject(\Throwable $reason): void
    {
        if ($this->state !== self::PENDING) {
            return;
        }
        $this->state = self::REJECTED;
        $this->reason = $reason;
        $this->flush();
    }

    public function isPending(): bool
    {
        return $this->state === self::PENDING;
    }

    /**
     * @param \Closure(T): void $onResolved
     * @param \Closure(\Throwable): void $onRejected
     */
    public function then(\Closure $onResolved, \Closure $onRejected): void
    {
        $this->callbacks[] = [$onResolved, $onRejected];
        if ($this->state !== self::PENDING) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        $callbacks = $this->callbacks;
        $this->callbacks = [];
        foreach ($callbacks as [$onResolved, $onRejected]) {
            if ($this->state === self::RESOLVED) {
                $onResolved($this->value);
            } else {
                $onRejected($this->reason ?? new \LogicException('Rejected without a reason'));
            }
        }
    }
}
