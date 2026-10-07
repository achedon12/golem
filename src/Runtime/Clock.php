<?php

declare(strict_types=1);

namespace Golem\Runtime;

use Golem\Runtime\Coroutine\Deferred;
use Golem\WaitTimedOut;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;

/**
 * Turns server ticks into things a test can wait for.
 */
final class Clock
{
    public function __construct(private readonly PluginBase $plugin)
    {
    }

    /**
     * @return Deferred<null>
     */
    public function wait(int $ticks): Deferred
    {
        /** @var Deferred<null> $deferred */
        $deferred = new Deferred();
        if ($ticks <= 0) {
            $deferred->resolve(null);

            return $deferred;
        }

        $this->plugin->getScheduler()->scheduleDelayedTask(
            new ClosureTask(static fn () => $deferred->resolve(null)),
            $ticks,
        );

        return $deferred;
    }

    /**
     * @template T
     * @param \Closure(): (T|null|false) $condition checked once per tick until it returns something truthy
     * @return Deferred<T>
     */
    public function until(\Closure $condition, int $timeoutTicks, string $description): Deferred
    {
        /** @var Deferred<T> $deferred */
        $deferred = new Deferred();
        $elapsed = 0;

        $check = static function () use ($condition, $deferred, &$elapsed, $timeoutTicks, $description): bool {
            try {
                $value = $condition();
            } catch (\Throwable $e) {
                $deferred->reject($e);

                return true;
            }
            if ((bool) $value) {
                $deferred->resolve($value);

                return true;
            }
            if ($elapsed++ >= $timeoutTicks) {
                $deferred->reject(new WaitTimedOut(sprintf(
                    'Timed out after %d ticks waiting for %s',
                    $timeoutTicks,
                    $description,
                )));

                return true;
            }

            return false;
        };

        if ($check()) {
            return $deferred;
        }

        $handler = null;
        $handler = $this->plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(static function () use ($check, &$handler): void {
            if ($check()) {
                $handler?->cancel();
            }
        }), 1);

        return $deferred;
    }
}
