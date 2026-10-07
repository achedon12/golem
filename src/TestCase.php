<?php

declare(strict_types=1);

namespace Golem;

use Golem\Assert\Assertions;
use Golem\Runtime\Coroutine\Deferred;
use Golem\Runtime\Runtime;
use pocketmine\plugin\Plugin;
use pocketmine\Server;
use pocketmine\world\Position;
use pocketmine\world\World;

/**
 * Base class for Golem tests.
 *
 * Every public method whose name starts with "test" runs inside a live server, on
 * the main thread, after your plugin was enabled. A test that needs time to pass
 * (a golem joining, a delayed task, a cooldown) is written as a generator and
 * yields what it waits for:
 *
 *     public function testWelcomesNewPlayers(): \Generator
 *     {
 *         $steve = yield $this->golem('Steve');
 *
 *         $this->assertReceivedMessage($steve, 'Welcome Steve!');
 *     }
 */
abstract class TestCase
{
    use Assertions;

    /**
     * Runs before each test. Declare it `: void`, or `: \Generator` to wait for things.
     *
     * @return \Generator<mixed, mixed, mixed, mixed>|null
     */
    protected function setUp() // untyped on purpose: overrides may return void or Generator
    {
        return null;
    }

    /**
     * Runs after each test, even a failed one. Golems are disconnected afterwards.
     */
    protected function tearDown(): void
    {
    }

    /**
     * Spawns a simulated player and waits until it is in the world.
     *
     * @return Deferred<Golem> yield it to get the golem
     */
    final protected function golem(?string $name = null): Deferred
    {
        return Runtime::get()->golems->spawn($name);
    }

    /**
     * Lets the server run for a number of ticks (20 ticks = 1 second).
     *
     * @return Deferred<null>
     */
    final protected function wait(int $ticks): Deferred
    {
        return Runtime::get()->clock->wait($ticks);
    }

    /**
     * Waits until the condition returns something truthy, checking once per tick.
     * Resolves with that value, or throws {@see WaitTimedOut}.
     *
     * @template T
     * @param \Closure(): T $condition
     * @return Deferred<T>
     */
    final protected function waitUntil(\Closure $condition, int $timeoutTicks = 100, string $description = 'the condition'): Deferred
    {
        return Runtime::get()->clock->until($condition, $timeoutTicks, $description);
    }

    final protected function server(): Server
    {
        return Server::getInstance();
    }

    /**
     * The plugin under test, or another loaded plugin by name.
     */
    final protected function plugin(?string $name = null): Plugin
    {
        $name ??= Runtime::get()->subject;

        return $this->server()->getPluginManager()->getPlugin($name)
            ?? throw new \LogicException("Plugin \"$name\" is not loaded");
    }

    final protected function world(): World
    {
        return $this->server()->getWorldManager()->getDefaultWorld()
            ?? throw new \LogicException('The server has no default world');
    }

    /**
     * Where new players appear in the default world.
     */
    final protected function spawn(): Position
    {
        return $this->world()->getSpawnLocation();
    }

    /**
     * Ends the test as skipped.
     */
    final protected function skip(string $reason = ''): never
    {
        throw new TestSkipped($reason);
    }

    /**
     * @internal
     */
    final public function runSetUp(): ?\Generator
    {
        $result = $this->setUp();

        return $result instanceof \Generator ? $result : null;
    }

    /**
     * @internal
     */
    final public function runTearDown(): void
    {
        $this->tearDown();
    }
}
