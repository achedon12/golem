<?php

declare(strict_types=1);

namespace Golem\Runtime;

use Golem\Runtime\Coroutine\Coroutine;
use Golem\TestCase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;

/**
 * The test currently executing in {@see TestRunner}.
 *
 * @internal
 */
final class RunningTest
{
    /** @var TaskHandler<ClosureTask>|null */
    public ?TaskHandler $timeout = null;

    public function __construct(
        public readonly TestDefinition $definition,
        public readonly TestCase $instance,
        public readonly Coroutine $coroutine,
        public readonly int $generation,
        public readonly int|float $startedAt,
        public readonly int $startTick,
    ) {
    }
}
