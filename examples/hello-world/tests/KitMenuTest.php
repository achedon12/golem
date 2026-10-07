<?php

declare(strict_types=1);

namespace Example\HelloWorld\Tests;

use Generator;
use Golem\TestCase;
use pocketmine\block\inventory\ChestInventory;
use pocketmine\item\VanillaItems;

final class KitMenuTest extends TestCase
{
    public function testPickingAKitGivesACopy(): Generator
    {
        $steve = yield $this->golem('Steve');
        $steve->chat('/kits');
        yield $steve->waitForWindow();
        $this->assertWindowOpen($steve, ChestInventory::class);

        $this->assertFalse($steve->clickSlot(0), 'the menu is read-only: the click is cancelled');

        $this->assertHasItem($steve, VanillaItems::IRON_SWORD()->setCustomName('Warrior kit'));
        $this->assertReceivedMessage($steve, 'You picked the Warrior kit.');
        $this->assertTrue($steve->window()?->getItem(0)->equals(VanillaItems::IRON_SWORD(), false, false));

        yield $this->wait(1);
        $this->assertNoWindowOpen($steve);
    }

    public function testClosingTheMenu(): Generator
    {
        $steve = yield $this->golem('Steve');
        $steve->chat('/kits');

        $steve->closeWindow();

        $this->assertNoWindowOpen($steve);
        $this->assertNotHasItem($steve, VanillaItems::BOW()->setCustomName('Archer kit'));
    }
}
