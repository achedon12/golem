---
description: "How to write Golem tests for PocketMine-MP plugins: test methods, generators to wait for ticks and players, setUp, isolation and attributes."
---

# Writing tests

## Where tests live

Golem loads every `.php` file in your tests folder (`tests/` by default, see
[Configuration](configuration.md)) and runs every concrete class extending `Golem\TestCase`.
Helper classes and abstract base tests can live in the same folder.

Any namespace works. A convention that keeps IDEs happy is your plugin's namespace plus `\Tests`,
declared in `composer.json`:

```json
"autoload-dev": {
    "psr-4": { "MyName\\MyPlugin\\Tests\\": "tests/" }
}
```

## Test methods

A test is a public, non-static method whose name starts with `test`, or any public method marked
with `#[Golem\Attribute\Test]`. Tests run one after the other, in file order, on the server's main
thread. Your plugin is already enabled and the default world is loaded.

```php
public function testConfigHasDefaults(): void
{
    $this->assertSame(5, $this->plugin()->getConfig()->get('cooldown'));
}
```

## Waiting: tests as generators

Most interesting behaviour takes time: a player joining, a delayed task, a cooldown. Declare the
test as returning `Generator` and **yield** what you are waiting for. The test pauses, the server
keeps ticking, and the test resumes when the value is ready.

| Yield this | To wait for | You get back |
| --- | --- | --- |
| `$this->golem('Steve')` | a simulated player to join | the `Golem` |
| `$this->golems(['Steve', 'Alex'])` or `$this->golems(20)` | several golems to join, all together | a list of `Golem`, in order |
| `$this->wait(40)` | 40 ticks (20 ticks = 1 second) | `null` |
| `$this->waitUntil(fn () => ..., 100)` | a condition, checked every tick | the condition's value |
| any PocketMine `Promise` | the promise to resolve | its value |

```php
public function testRewardsAfterTenSeconds(): Generator
{
    $steve = yield $this->golem('Steve');

    yield $this->wait(10 * 20);

    $this->assertHasItem($steve, VanillaItems::DIAMOND());
}

public function testLoadsTheLeaderboard(): Generator
{
    $board = yield $this->waitUntil(
        fn () => $this->plugin()->getLeaderboard(),   // returns null until loaded
        timeoutTicks: 200,
        description: 'the leaderboard to load',
    );

    $this->assertCount(10, $board->getEntries());
}
```

`waitUntil()` throws `Golem\WaitTimedOut` when the condition is still falsy after the timeout,
which fails the test and points at the `yield` that was waiting.

## setUp and tearDown

`setUp()` runs before each test and may itself be a generator, which is handy to share golems:

```php
final class ShopTest extends TestCase
{
    private Golem $buyer;

    protected function setUp(): Generator
    {
        $this->buyer = yield $this->golem('Buyer');
        $this->buyer->give(VanillaItems::EMERALD()->setCount(10));
    }

    public function testBuysASword(): void
    {
        $this->buyer->chat('/shop buy sword');

        $this->assertHasItem($this->buyer, VanillaItems::DIAMOND_SWORD());
    }
}
```

`tearDown()` runs after each test, even a failed one. After it, Golem disconnects every golem the
test spawned and revokes their op status, so each test starts with an empty server.

## Isolation

Golems are cleaned up between tests, but the shared world and your plugin's state are not: a block
broken in one test stays broken in the next, and data your plugin keeps in memory survives.

When a test changes the world, give it a world of its own with `#[FreshWorld]`: it runs in a brand
new superflat world, which becomes the default world (golems spawn there, `$this->world()` returns
it) and is deleted afterwards.

```php
use Golem\Attribute\FreshWorld;

#[FreshWorld]
public function testExplosionsDestroyBlocks(): Generator
{
    $steve = yield $this->golem('Steve');
    // ...
}
```

The same thing from code is `$this->freshWorld()`, to call before spawning golems, usually in
`setUp()`. Plugin state is yours to reset in `setUp()`.

### Worlds from a template

Minigames need their map. Put a world folder (the one PocketMine saves, with `level.dat`) in your
repository and run tests in a copy of it:

```php
use Golem\Attribute\World;

#[World('tests/worlds/arena')]
final class ArenaTest extends TestCase
{
    public function testTeamsSpawnOnTheirSide(): Generator { /* ... */ }
}
```

Each test gets its own copy, set as the default world and deleted afterwards: the template is
never modified. The chunks around its spawn are loaded right away, so a test can read or change
blocks before any golem joins. From code: `$this->loadWorld('tests/worlds/arena')`.

Mark world folders as binary in `.gitattributes` (`**/worlds/** binary`), or git may rewrite the
line endings of LevelDB files and corrupt them.


### Flaky tests

A test that passes on its own but fails once in a while usually depends on timing, or on what a
test before it left behind. Run every test several times, in a random order:

```bash
vendor/bin/golem --repeat=10 --random-order
```

```text
  Tests:    2 failed, 68 passed (140 assertions)
  Flaky:     1 test passed in some runs and failed in others
             KitMenuTest › picking a kit gives a copy · failed 2 of 10 runs
  Order:     random, seed 857451 (same order again: --random-order=857451)
```

Each repetition shuffles the tests again. Pass the printed seed to `--random-order=<seed>` to run
them in the same order and reproduce a failure.

## Attributes

```php
use Golem\Attribute\FreshWorld;
use Golem\Attribute\Skip;
use Golem\Attribute\Test;
use Golem\Attribute\Timeout;
use Golem\Attribute\World;

#[Timeout(ticks: 600)]                   // every test of the class may take 30 seconds
final class BossFightTest extends TestCase
{
    #[Test]                              // runs although the name does not start with "test"
    public function bossSpawnsAtNight(): Generator { /* ... */ }

    #[FreshWorld]                        // runs in its own world, deleted afterwards
    public function testArenaCollapses(): Generator { /* ... */ }

    #[World('tests/worlds/arena')]       // runs in a copy of a world folder
    public function testTeamsSpawnApart(): Generator { /* ... */ }

    #[Skip('waiting for the 2.0 loot tables')]
    public function testLoot(): void { /* ... */ }
}
```

The default timeout is 200 ticks (10 seconds) per test. You can also skip from inside a test with
`$this->skip('reason')`.

## Coverage

`vendor/bin/golem --coverage` tells you which parts of your plugin the tests never reached:

```text
  Tests:    34 passed, 1 skipped (72 assertions)
  Coverage: commands 3/3 · listeners 6/6 · lines 94.3% (132/140)
            src/KitMenu.php 86% · not run: 38, 56, 61, 70
            src/HelloWorld.php 94% · not run: 51, 129, 131, 151
```

It counts the commands registered by your plugin (any way they are run: chat, `command()`, the
console) and the event listeners it registered, called with the event actually handled, then lists
those the tests never reached.

It also measures which lines of `src/` ran, and lists the files with lines that never did. That
needs pcov or Xdebug in the server's PHP: PocketMine's PHP build ships Xdebug, disabled, and
Golem turns it on for the run. Only the main thread is covered, not code running in async tasks.
Files the tests never load do not appear.

Collecting lines makes the server several times slower. While it runs, test timeouts and the time
a golem has to join are tripled, and `assertTpsAbove()` skips the test instead of measuring a
server slowed down by the coverage.

`--coverage-clover=<file>` also writes the lines as a Clover XML report, the format Codecov,
Coveralls and most CI tools read:

```yaml
- run: composer install --no-interaction
- run: vendor/bin/golem --coverage-clover=coverage.xml
- uses: codecov/codecov-action@v5
  with:
    files: coverage.xml
```

## Data providers

Run the same test with several inputs: point `#[DataProvider]` at a public static method that
returns one array of arguments per data set. Each data set runs, and is reported, as its own test.

```php
use Golem\Attribute\DataProvider;

#[DataProvider('buttons')]
public function testEveryMenuButtonAnswers(string $button, string $reply): Generator
{
    $steve = yield $this->golem('Steve');
    $steve->chat('/menu');

    $steve->clickButton($button);

    $this->assertSame($reply, $steve->lastMessage());
}

public static function buttons(): iterable
{
    yield 'spawn' => ['Spawn', 'Teleported to spawn.'];
    yield 'daytime' => ['Daytime', 'Good morning!'];
}
```

The output reads `every menu button answers (spawn)`, and `--filter=spawn` runs just that data set.
Unnamed data sets are numbered `#0`, `#1`…

## Useful helpers

| Method | Returns |
| --- | --- |
| `$this->server()` | the `Server` |
| `$this->plugin()` | your plugin instance (or `plugin('Other')` for another one) |
| `$this->world()` | the default world, a superflat world created fresh for each run |
| `$this->spawn()` | the default world's spawn position |
| `$this->item('diamond_sword', 3)` | an item by its name, as in `/give` |
| `$this->freshWorld()` | switches the test to a brand new world (see [Isolation](#isolation)) |
| `$this->loadWorld($path)` | switches the test to a copy of a world folder (see [Worlds from a template](#worlds-from-a-template)) |
