---
description: "Every Golem assertion: PHPUnit-style checks plus Minecraft-aware ones for messages, titles, forms, inventories, health, positions and blocks."
---

# Assertions

Every assertion takes an optional last `$message` argument that replaces the default failure
message. When one fails, the test stops and the report shows what was expected, what was found,
and the line of your test.

## General

| Assertion | Passes when |
| --- | --- |
| `assertTrue($actual)` / `assertFalse($actual)` | `$actual === true` / `=== false` |
| `assertNull($actual)` / `assertNotNull($actual)` | `$actual` is / is not `null` |
| `assertSame($expected, $actual)` | `$expected === $actual` |
| `assertNotSame($unexpected, $actual)` | `$unexpected !== $actual` |
| `assertEquals($expected, $actual)` | `$expected == $actual` (loose, handy for value objects) |
| `assertCount(int $count, $haystack)` | an array or `Countable` has exactly `$count` elements |
| `assertEmpty($actual)` / `assertNotEmpty($actual)` | an array, `Countable` or string is (not) empty |
| `assertContains($needle, $haystack)` | an iterable contains `$needle` (strictly), or a string contains a substring |
| `assertNotContains($needle, $haystack)` | the opposite |
| `assertInstanceOf(string $class, $actual)` | `$actual instanceof $class` |
| `assertGreaterThan($threshold, $actual)` / `assertLessThan(...)` | numeric comparison |
| `assertThrows(string $class, Closure $callback, ?string $messageContains = null)` | the callback throws that exception |
| `fail(string $message)` | never: fails the test right away |

## Minecraft

Text comparisons ignore colour codes on both sides and match substrings.

| Assertion | Passes when |
| --- | --- |
| `assertReceivedMessage(Golem $golem, string $text)` | one of the golem's chat messages contains `$text` |
| `assertNotReceivedMessage(Golem $golem, string $text)` | none does |
| `assertTitle(Golem $golem, string $text)` | a title containing `$text` was shown |
| `assertActionBar(Golem $golem, string $text)` | an action bar message containing `$text` was shown |
| `assertScoreboardContains(Golem $golem, string $text)` | the sidebar scoreboard shows that text, in its title or a line |
| `assertBossBar(Golem $golem, string $titleContains, ?float $progress = null)` | a boss bar with that title (and progress, from 0.0 to 1.0) is on screen |
| `assertWindowOpen(Golem $golem, ?string $class = null)` / `assertNoWindowOpen(Golem $golem)` | an inventory window (optionally of that class, e.g. `ChestInventory::class`) is open / none is |
| `assertFormOpen(Golem $golem, ?string $titleContains = null)` | the golem has a form open (whose title contains the text) |
| `assertNoFormOpen(Golem $golem)` | no form is waiting for an answer |
| `assertSoundPlayed(Golem $golem, string $sound)` | the golem heard the sound: a named sound like `random.levelup`, or a sound event like `levelup` |
| `assertPacketSent(Golem $golem, string $class, ?Closure $filter = null)` | the server sent at least one packet of that class (matching the filter) |
| `assertOnline(Golem $golem)` / `assertOffline(Golem $golem)` | still connected / was kicked or quit |
| `assertDead(Golem $golem)` / `assertAlive(Golem $golem)` | the golem is on the death screen / alive |
| `assertKicked(Golem $golem, ?string $reasonContains = null)` | the server disconnected the golem (kick, ban, full server…), optionally with that text on the disconnection screen |
| `assertHasItem(Golem $golem, Item $item, ?int $count = null)` | the inventory holds at least `$count` matching items (defaults to the item's own count) |
| `assertNotHasItem(Golem $golem, Item $item)` | the inventory holds none |
| `assertHealth(Golem $golem, float $health)` | the golem has exactly that much health (20 = ten hearts) |
| `assertGamemode(Golem $golem, GameMode $mode)` | the golem is in that game mode |
| `assertAt(Golem $golem, Vector3 $position, float $tolerance = 0.5)` | the golem stands within `$tolerance` blocks of the position |
| `assertHasPermission(Golem $golem, string $permission)` / `assertNotHasPermission(...)` | permission check |
| `assertBlockAt(Vector3 $position, Block $block)` | the default world has that block, in the same state, at the position |

## Writing your own

Use `check()` so your assertion is counted and reported like the built-in ones:

```php
private function assertHasCoins(Golem $golem, int $expected): void
{
    $coins = $this->plugin()->getEconomy()->getCoins($golem->player());

    $this->check(
        $coins === $expected,
        "{$golem->name()} has the wrong balance",
        expected: (string) $expected,
        actual: (string) $coins,
    );
}
```
