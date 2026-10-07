<?php

declare(strict_types=1);

namespace Golem\Tests;

use Generator;
use Golem\Golem;
use Golem\TestCase;
use pocketmine\console\ConsoleCommandSender;
use pocketmine\event\player\PlayerDataSaveEvent;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;

/**
 * Golem testing its own server plugin: an operator golem drives /golem.
 */
final class GolemCommandTest extends TestCase
{
    private Golem $admin;

    protected function setUp(): Generator
    {
        $this->admin = yield $this->golem('Admin');
        $this->admin->op();
    }

    protected function tearDown(): void
    {
        // golems spawned through /golem belong to the plugin, not to the test
        $this->server()->dispatchCommand(new ConsoleCommandSender($this->server(), $this->server()->getLanguage()), 'golem remove all');
    }

    public function testSpawnsAGolemThatChats(): Generator
    {
        yield from $this->spawnBob();

        $this->assertInstanceOf(Player::class, $this->server()->getPlayerExact('Bob'));

        $this->admin->command('golem Bob chat hello there');
        $this->assertReceivedMessage($this->admin, 'hello there');

        $this->admin->command('golem list');
        $this->assertReceivedMessage($this->admin, '1 golem(s): Bob');
    }

    public function testRefusesTheNameOfAnOnlinePlayer(): void
    {
        $this->admin->command('golem spawn Admin');

        $this->assertReceivedMessage($this->admin, 'Admin is already online');
        $this->assertOnline($this->admin);
    }

    public function testRefusesTheNameOfARealPlayer(): void
    {
        $this->server()->addOp('Notch');
        try {
            $this->admin->command('golem spawn Notch');

            $this->assertReceivedMessage($this->admin, 'Notch is a real player of this server');
            $this->assertNull($this->server()->getPlayerExact('Notch'));
        } finally {
            $this->server()->removeOp('Notch');
        }
    }

    public function testGolemsLeaveNoPlayerData(): Generator
    {
        yield from $this->spawnBob();
        $bob = $this->server()->getPlayerExact('Bob');
        $this->assertNotNull($bob);

        $event = new PlayerDataSaveEvent(new CompoundTag(), 'Bob', $bob);
        $event->call();

        $this->assertTrue($event->isCancelled(), 'saving a golem must be cancelled');
    }

    public function testRemovingAGolemRevokesTheOpItWasGiven(): Generator
    {
        yield from $this->spawnBob();
        $this->admin->command('op Bob');

        $this->admin->command('golem remove all');

        $this->assertFalse($this->server()->isOp('Bob'), 'Bob was not op before spawning, so it should not stay op');
    }

    public function testWalksAndRemovesGolems(): Generator
    {
        yield from $this->spawnBob();

        $this->admin->command('golem Bob walk 3 0');
        yield $this->waitUntil(fn () => in_array('[Golem] Bob arrived', $this->admin->messages(), true), 60, 'Bob to arrive');

        $this->admin->command('golem remove Bob');
        $this->assertNull($this->server()->getPlayerExact('Bob'));
        $this->assertReceivedMessage($this->admin, 'Removed Bob');
    }

    public function testShowsWhatAGolemReceived(): Generator
    {
        yield from $this->spawnBob();

        $this->admin->chat('hi Bob');
        $this->admin->command('golem Bob inbox');

        $this->assertReceivedMessage($this->admin, 'Last messages of Bob:');
        $this->assertReceivedMessage($this->admin, '<Admin> hi Bob');
    }

    /**
     * Spawns Bob through the plugin and waits until it says he is in.
     *
     * @return Generator<mixed, mixed, mixed, void>
     */
    private function spawnBob(): Generator
    {
        $this->admin->command('golem spawn Bob');
        yield $this->waitUntil(
            fn () => in_array('[Golem] Bob joined the server', $this->admin->messages(), true),
            100,
            'Bob to join',
        );
    }

    public function testRegularPlayersCannotUseIt(): Generator
    {
        $steve = yield $this->golem('Steve');

        $steve->command('golem spawn Bob');

        $this->assertNull($this->server()->getPlayerExact('Bob'));
        $this->assertNotReceivedMessage($steve, 'joined the server');
    }
}
