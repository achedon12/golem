<?php

declare(strict_types=1);

namespace Example\HelloWorld\Tests;

use Generator;
use Golem\TestCase;
use pocketmine\entity\Entity;
use pocketmine\item\VanillaItems;

final class GuideAndRespawnTest extends TestCase
{
    public function testTheGuideHelpsPlayers(): Generator
    {
        $steve = yield $this->golem('Steve');

        $steve->interactEntity($this->guide());

        $this->assertReceivedMessage($steve, 'Guide: type /menu to get started!');
    }

    public function testDyingPlayersGetANewKitOnRespawn(): Generator
    {
        $steve = yield $this->golem('Steve');
        $steve->player()->getInventory()->clearAll();

        $steve->player()->kill();
        $this->assertDead($steve);

        $this->assertTrue($steve->respawn());
        yield $this->wait(1);

        $this->assertAlive($steve);
        $this->assertReceivedMessage($steve, 'Back in the game!');
        $this->assertHasItem($steve, VanillaItems::BREAD(), 3);
    }

    private function guide(): Entity
    {
        foreach ($this->world()->getEntities() as $entity) {
            if ($entity->getNameTag() === 'Guide') {
                return $entity;
            }
        }
        $this->fail('The guide is not in the world');
    }
}
