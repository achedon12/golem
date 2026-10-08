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

    private string $currentMethod = '';

    private ?string $currentDataName = null;

    private int $snapshotIndex = 0;

    private int $snapshotsWritten = 0;

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
     * Spawns several golems at once, all joining together, and waits until they are all
     * in the world: a number of them (named like golem()), or one per name.
     *
     *     [$steve, $alex] = yield $this->golems(['Steve', 'Alex']);
     *     $crowd = yield $this->golems(20);
     *
     * @param int|list<string> $golems
     * @return Deferred<list<Golem>> yield it to get the golems, in order
     */
    final protected function golems(int|array $golems): Deferred
    {
        $factory = Runtime::get()->golems;
        $names = is_int($golems) ? array_fill(0, max(0, $golems), null) : $golems;

        return Deferred::all(array_map(static fn (?string $name) => $factory->spawn($name), $names));
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
     * @param \Closure(): (T|null|false) $condition
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
     * Switches to a brand new superflat world for the rest of the test: golems spawned
     * afterwards appear there, and {@see world()} returns it. It is deleted after the test.
     * Call it before spawning golems, typically in setUp(), or use #[FreshWorld].
     */
    final protected function freshWorld(): World
    {
        return Runtime::get()->worlds->fresh();
    }

    /**
     * Switches to a copy of a world folder (relative to the plugin folder) for the rest
     * of the test, like {@see freshWorld()}. The template itself is never modified.
     * Call it before spawning golems, or use #[World('path')].
     */
    final protected function loadWorld(string $path): World
    {
        return Runtime::get()->worlds->fromTemplate($path);
    }

    /**
     * Where new players appear in the default world.
     */
    final protected function spawn(): Position
    {
        return $this->world()->getSpawnLocation();
    }

    /**
     * Compares a value with the snapshot saved by a previous run, or saves it on the first
     * run. Snapshots live next to the test, in __snapshots__/, and are meant to be committed.
     * Run `golem --update-snapshots` to accept intended changes.
     *
     * @param string|null $name names the snapshot; by default they are numbered in order
     */
    final protected function assertMatchesSnapshot(mixed $value, ?string $name = null, string $message = ''): void
    {
        $runtime = Runtime::get();
        $actual = \Golem\Assert\Snapshot::encode($value);
        $file = $this->snapshotFile($name ?? (string) ++$this->snapshotIndex);

        if (!is_file($file) || $runtime->updateSnapshots) {
            if (!is_file($file) && $runtime->ci && !$runtime->updateSnapshots) {
                $this->fail('The snapshot ' . basename($file) . ' does not exist. Run the tests locally to create it, and commit it.');
            }
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, $actual);
            $this->snapshotsWritten++;
            $this->check(true, '');

            return;
        }

        $expected = (string) file_get_contents($file);
        $this->check(
            $expected === $actual,
            $message ?: 'The value does not match the snapshot ' . basename($file) . ' (run with --update-snapshots if the change is intended)',
            rtrim($expected),
            rtrim($actual),
        );
    }

    private function snapshotFile(string $name): string
    {
        $class = new \ReflectionClass($this);
        $data = $this->currentDataName !== null ? ' (' . preg_replace('/[^A-Za-z0-9_.-]+/', '_', $this->currentDataName) . ')' : '';
        $safe = (string) preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name);

        return dirname((string) $class->getFileName()) . '/__snapshots__/' . $class->getShortName() . '/' . $this->currentMethod . $data . '.' . $safe . '.json';
    }

    /**
     * @internal
     */
    final public function bindTest(string $method, ?string $dataName): void
    {
        $this->currentMethod = $method;
        $this->currentDataName = $dataName;
    }

    /**
     * @internal
     */
    final public function snapshotsWritten(): int
    {
        return $this->snapshotsWritten;
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
