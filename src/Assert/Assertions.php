<?php

declare(strict_types=1);

namespace Golem\Assert;

use Golem\Golem;
use pocketmine\block\Block;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\Server;

/**
 * Expectations available in every test.
 *
 * The general ones mirror PHPUnit so they feel familiar; the Minecraft ones read
 * straight from a {@see Golem} or the world.
 */
trait Assertions
{
    private int $assertionCount = 0;

    /**
     * @internal
     */
    public function assertionCount(): int
    {
        return $this->assertionCount;
    }

    // --------------------------------------------------------------- general

    final protected function assertTrue(mixed $actual, string $message = ''): void
    {
        $this->check($actual === true, $message ?: 'Expected true', 'true', Exporter::export($actual));
    }

    final protected function assertFalse(mixed $actual, string $message = ''): void
    {
        $this->check($actual === false, $message ?: 'Expected false', 'false', Exporter::export($actual));
    }

    final protected function assertNull(mixed $actual, string $message = ''): void
    {
        $this->check($actual === null, $message ?: 'Expected null', 'null', Exporter::export($actual));
    }

    final protected function assertNotNull(mixed $actual, string $message = ''): void
    {
        $this->check($actual !== null, $message ?: 'Expected a value, got null');
    }

    /**
     * Strict comparison (===).
     */
    final protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->check(
            $expected === $actual,
            $message ?: 'Expected both values to be identical',
            Exporter::export($expected),
            Exporter::export($actual),
        );
    }

    final protected function assertNotSame(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        $this->check(
            $unexpected !== $actual,
            $message ?: 'Expected a different value than ' . Exporter::export($unexpected),
        );
    }

    /**
     * Loose comparison (==), useful for floats and value objects.
     */
    final protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->check(
            $expected == $actual,
            $message ?: 'Expected both values to be equal',
            Exporter::export($expected),
            Exporter::export($actual),
        );
    }

    /**
     * @param \Countable|array<mixed> $haystack
     */
    final protected function assertCount(int $expected, \Countable|array $haystack, string $message = ''): void
    {
        $count = count($haystack);
        $this->check(
            $count === $expected,
            $message ?: "Expected $expected element(s), got $count",
            (string) $expected,
            (string) $count,
        );
    }

    /**
     * @param \Countable|array<mixed>|string $actual
     */
    final protected function assertEmpty(\Countable|array|string $actual, string $message = ''): void
    {
        $empty = is_string($actual) ? $actual === '' : count($actual) === 0;
        $this->check($empty, $message ?: 'Expected an empty value', null, Exporter::export(is_array($actual) || is_string($actual) ? $actual : count($actual)));
    }

    /**
     * @param \Countable|array<mixed>|string $actual
     */
    final protected function assertNotEmpty(\Countable|array|string $actual, string $message = ''): void
    {
        $empty = is_string($actual) ? $actual === '' : count($actual) === 0;
        $this->check(!$empty, $message ?: 'Expected a non-empty value');
    }

    /**
     * Checks that a list contains an element (strictly), or that a string contains a substring.
     *
     * @param iterable<mixed>|string $haystack
     */
    final protected function assertContains(mixed $needle, iterable|string $haystack, string $message = ''): void
    {
        if (is_string($haystack)) {
            $found = is_string($needle) && str_contains($haystack, $needle);
        } else {
            $found = false;
            foreach ($haystack as $item) {
                if ($item === $needle) {
                    $found = true;
                    break;
                }
            }
        }

        $this->check(
            $found,
            $message ?: 'Expected ' . Exporter::export($needle) . ' to be contained in the value',
            null,
            Exporter::export(is_string($haystack) ? $haystack : iterator_to_array($haystack, false)),
        );
    }

    /**
     * @param iterable<mixed>|string $haystack
     */
    final protected function assertNotContains(mixed $needle, iterable|string $haystack, string $message = ''): void
    {
        $contained = is_string($haystack)
            ? is_string($needle) && str_contains($haystack, $needle)
            : in_array($needle, iterator_to_array($haystack, false), true);

        $this->check(!$contained, $message ?: 'Did not expect ' . Exporter::export($needle) . ' in the value');
    }

    /**
     * @param class-string $class
     */
    final protected function assertInstanceOf(string $class, mixed $actual, string $message = ''): void
    {
        $this->check(
            $actual instanceof $class,
            $message ?: "Expected an instance of $class",
            $class,
            get_debug_type($actual),
        );
    }

    final protected function assertGreaterThan(int|float $threshold, int|float $actual, string $message = ''): void
    {
        $this->check($actual > $threshold, $message ?: "Expected a value greater than $threshold", "> $threshold", (string) $actual);
    }

    final protected function assertLessThan(int|float $threshold, int|float $actual, string $message = ''): void
    {
        $this->check($actual < $threshold, $message ?: "Expected a value less than $threshold", "< $threshold", (string) $actual);
    }

    /**
     * @param class-string<\Throwable> $class
     * @param \Closure(): mixed $callback
     */
    final protected function assertThrows(string $class, \Closure $callback, ?string $messageContains = null): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if (!$e instanceof $class) {
                $this->check(false, "Expected $class to be thrown", $class, $e::class . ': ' . $e->getMessage());

                return;
            }
            if ($messageContains !== null) {
                $this->check(
                    str_contains($e->getMessage(), $messageContains),
                    'The exception message does not contain the expected text',
                    Exporter::export($messageContains),
                    Exporter::export($e->getMessage()),
                );

                return;
            }
            $this->check(true, '');

            return;
        }

        $this->check(false, "Expected $class to be thrown, but nothing was thrown");
    }

    /**
     * Fails the test right away.
     */
    final protected function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }

    // ------------------------------------------------------------- minecraft

    /**
     * Checks that the golem received a chat message containing the given text
     * (colour codes are ignored on both sides).
     */
    final protected function assertReceivedMessage(Golem $golem, string $text, string $message = ''): void
    {
        $text = self::clean($text);
        $found = false;
        foreach ($golem->messages() as $received) {
            if (str_contains($received, $text)) {
                $found = true;
                break;
            }
        }

        $this->check(
            $found,
            $message ?: "{$golem->name()} never received a message containing \"$text\"",
            Exporter::export($text),
            Exporter::export($golem->messages()),
        );
    }

    final protected function assertNotReceivedMessage(Golem $golem, string $text, string $message = ''): void
    {
        $text = self::clean($text);
        foreach ($golem->messages() as $received) {
            if (str_contains($received, $text)) {
                $this->check(false, $message ?: "{$golem->name()} was not supposed to receive \"$text\"", null, Exporter::export($received));

                return;
            }
        }
        $this->check(true, '');
    }

    /**
     * Checks that a title containing the given text was shown to the golem.
     */
    final protected function assertTitle(Golem $golem, string $text, string $message = ''): void
    {
        $this->assertTextIn($golem->titles(), $text, $message ?: "{$golem->name()} was never shown the title \"$text\"");
    }

    final protected function assertActionBar(Golem $golem, string $text, string $message = ''): void
    {
        $this->assertTextIn($golem->actionBars(), $text, $message ?: "{$golem->name()} never saw \"$text\" in the action bar");
    }

    /**
     * Checks that the golem has a form open, optionally one whose title contains the given text.
     */
    final protected function assertFormOpen(Golem $golem, ?string $titleContains = null, string $message = ''): void
    {
        if ($golem->form() === null) {
            $this->check(false, $message ?: "{$golem->name()} has no form open");

            return;
        }
        if ($titleContains === null) {
            $this->check(true, '');

            return;
        }

        $title = $golem->formData()['title'] ?? '';
        $title = self::clean(is_string($title) ? $title : '');
        $this->check(
            str_contains($title, self::clean($titleContains)),
            $message ?: 'The open form has a different title',
            Exporter::export($titleContains),
            Exporter::export($title),
        );
    }

    final protected function assertNoFormOpen(Golem $golem, string $message = ''): void
    {
        $this->check($golem->form() === null, $message ?: "{$golem->name()} has a form open", null, Exporter::export($golem->form() !== null ? $golem->formData() : null));
    }

    final protected function assertOnline(Golem $golem, string $message = ''): void
    {
        $this->check($golem->isOnline(), $message ?: "{$golem->name()} is not connected");
    }

    final protected function assertOffline(Golem $golem, string $message = ''): void
    {
        $this->check(!$golem->isOnline(), $message ?: "{$golem->name()} is still connected");
    }

    /**
     * Checks that the golem's inventory holds at least `$count` items of the given type
     * (defaults to the count of the item passed in).
     */
    final protected function assertHasItem(Golem $golem, Item $item, ?int $count = null, string $message = ''): void
    {
        $wanted = $count ?? $item->getCount();
        $owned = 0;
        foreach ($golem->player()->getInventory()->getContents() as $slot) {
            if ($slot->canStackWith($item)) {
                $owned += $slot->getCount();
            }
        }

        $this->check(
            $owned >= $wanted,
            $message ?: "{$golem->name()} does not own enough {$item->getName()}",
            "at least $wanted",
            (string) $owned,
        );
    }

    final protected function assertNotHasItem(Golem $golem, Item $item, string $message = ''): void
    {
        foreach ($golem->player()->getInventory()->getContents() as $slot) {
            if ($slot->canStackWith($item)) {
                $this->check(false, $message ?: "{$golem->name()} was not supposed to own {$item->getName()}", '0', (string) $slot->getCount());

                return;
            }
        }
        $this->check(true, '');
    }

    final protected function assertHealth(Golem $golem, float $expected, string $message = ''): void
    {
        $health = $golem->player()->getHealth();
        $this->check(
            abs($health - $expected) < 0.001,
            $message ?: "{$golem->name()} has an unexpected health",
            Exporter::export($expected),
            Exporter::export($health),
        );
    }

    final protected function assertGamemode(Golem $golem, GameMode $expected, string $message = ''): void
    {
        $actual = $golem->player()->getGamemode();
        $this->check(
            $actual === $expected,
            $message ?: "{$golem->name()} is in the wrong game mode",
            Exporter::export($expected),
            Exporter::export($actual),
        );
    }

    /**
     * Checks that the golem stands within `$tolerance` blocks of a position.
     */
    final protected function assertAt(Golem $golem, Vector3 $expected, float $tolerance = 0.5, string $message = ''): void
    {
        $actual = $golem->position();
        $this->check(
            $actual->distance($expected) <= $tolerance,
            $message ?: "{$golem->name()} is not where it should be",
            Exporter::export($expected),
            Exporter::export($actual->asVector3()),
        );
    }

    final protected function assertHasPermission(Golem $golem, string $permission, string $message = ''): void
    {
        $this->check($golem->player()->hasPermission($permission), $message ?: "{$golem->name()} lacks the permission $permission");
    }

    final protected function assertNotHasPermission(Golem $golem, string $permission, string $message = ''): void
    {
        $this->check(!$golem->player()->hasPermission($permission), $message ?: "{$golem->name()} has the permission $permission");
    }

    /**
     * Checks the block at a position of the default world (same type and state).
     */
    final protected function assertBlockAt(Vector3 $position, Block $expected, string $message = ''): void
    {
        $actual = Server::getInstance()->getWorldManager()->getDefaultWorld()?->getBlock($position);
        $this->check(
            $actual !== null && $actual->isSameState($expected),
            $message ?: 'Unexpected block at ' . Exporter::export($position),
            Exporter::export($expected),
            Exporter::export($actual),
        );
    }

    // -------------------------------------------------------------- internal

    /**
     * Records an assertion and throws when it does not hold. Use it to write your own.
     */
    final protected function check(bool $condition, string $message, ?string $expected = null, ?string $actual = null): void
    {
        $this->assertionCount++;
        if (!$condition) {
            throw new AssertionFailed($message, $expected, $actual);
        }
    }

    /**
     * @param list<string> $texts
     */
    private function assertTextIn(array $texts, string $text, string $message): void
    {
        $text = self::clean($text);
        $found = false;
        foreach ($texts as $candidate) {
            if (str_contains($candidate, $text)) {
                $found = true;
                break;
            }
        }
        $this->check($found, $message, Exporter::export($text), Exporter::export($texts));
    }

    private static function clean(string $text): string
    {
        return \pocketmine\utils\TextFormat::clean($text);
    }
}
