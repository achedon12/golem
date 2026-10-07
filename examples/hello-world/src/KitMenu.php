<?php

declare(strict_types=1);

namespace Example\HelloWorld;

use pocketmine\block\tile\Chest;
use pocketmine\block\VanillaBlocks;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\Listener;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat;
use pocketmine\world\World;

/**
 * A read-only chest menu, the way InvMenu-style kit selectors work: clicking an item
 * gives a copy of it instead of taking it.
 */
final class KitMenu implements Listener
{
    private ?Inventory $menu = null;

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function install(World $world): void
    {
        $position = $world->getSafeSpawn()->add(0, 0, -3)->floor();
        $world->setBlock($position, VanillaBlocks::CHEST());
        $chest = $world->getTile($position);
        if (!$chest instanceof Chest) {
            return;
        }
        $this->menu = $chest->getInventory();
        $this->menu->setItem(0, VanillaItems::IRON_SWORD()->setCustomName('Warrior kit'));
        $this->menu->setItem(1, VanillaItems::BOW()->setCustomName('Archer kit'));
    }

    public function open(Player $player): void
    {
        if ($this->menu !== null) {
            $player->setCurrentWindow($this->menu);
        }
    }

    public function onTransaction(InventoryTransactionEvent $event): void
    {
        foreach ($event->getTransaction()->getActions() as $action) {
            if (!$action instanceof SlotChangeAction || $action->getInventory() !== $this->menu) {
                continue;
            }
            $event->cancel();
            $kit = $action->getSourceItem();
            if ($kit->isNull()) {
                return;
            }
            $player = $event->getTransaction()->getSource();
            $player->getInventory()->addItem(clone $kit);
            $player->sendMessage(TextFormat::GREEN . "You picked the {$kit->getCustomName()}.");
            $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(static fn () => $player->removeCurrentWindow()), 1);

            return;
        }
    }
}
