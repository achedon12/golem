<?php

declare(strict_types=1);

namespace Example\HelloWorld\Tests;

use Generator;
use Golem\TestCase;
use pocketmine\item\VanillaItems;

final class WelcomeTest extends TestCase
{
    public function testGreetsPlayersByName(): Generator
    {
        $steve = yield $this->golem('Steve');

        $this->assertReceivedMessage($steve, 'Welcome, Steve!');
        $this->assertTitle($steve, 'Hello!');
    }

    public function testGivesAStarterKit(): Generator
    {
        $alex = yield $this->golem('Alex');

        $this->assertHasItem($alex, VanillaItems::BREAD(), 3);
    }

    public function testRemindsAboutTheMenuAfterTwoSeconds(): Generator
    {
        $steve = yield $this->golem('Steve');
        $this->assertSame([], $steve->tips());

        yield $this->waitUntil(fn () => $steve->tips(), 60, 'the reminder tip');

        $this->assertSame(['Need help? Type /menu'], $steve->tips());
    }
}
