<?php

declare(strict_types=1);

namespace Example\HelloWorld;

use example\libgreeting\Greeting;
use pocketmine\command\Command;
use pocketmine\entity\Human;
use pocketmine\entity\Location;
use pocketmine\entity\Skin;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerEntityInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat;
use pocketmine\world\sound\XpLevelUpSound;

final class HelloWorld extends PluginBase implements Listener
{
    protected function onEnable(): void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->spawnGuide();
    }

    /**
     * A non-player character standing next to spawn: right-click it for help.
     */
    private function spawnGuide(): void
    {
        $world = $this->getServer()->getWorldManager()->getDefaultWorld();
        if ($world === null) {
            return;
        }
        $spawn = $world->getSafeSpawn();
        $guide = new Human(Location::fromObject($spawn->add(2, 0, 0), $world), new Skin('Standard_Custom', str_repeat("\x00", 64 * 64 * 4)));
        $guide->setNameTag('Guide');
        $guide->setNameTagAlwaysVisible();
        $guide->setCanSaveWithChunk(false);
        $guide->spawnToAll();
    }

    public function onInteractEntity(PlayerEntityInteractEvent $event): void
    {
        if ($event->getEntity()->getNameTag() === 'Guide') {
            $event->getPlayer()->sendMessage(TextFormat::YELLOW . 'Guide: type /menu to get started!');
        }
    }

    public function onRespawn(PlayerRespawnEvent $event): void
    {
        $player = $event->getPlayer();
        $player->sendMessage(TextFormat::GREEN . 'Back in the game!');
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(static function () use ($player): void {
            if ($player->isConnected()) {
                $player->getInventory()->addItem(VanillaItems::BREAD()->setCount(3));
            }
        }), 1);
    }

    public function onJoin(PlayerJoinEvent $event): void
    {
        $player = $event->getPlayer();
        $player->sendMessage(TextFormat::GREEN . Greeting::welcome($player->getName()));
        $player->sendTitle(TextFormat::GOLD . 'Hello!');
        $position = $player->getPosition();
        $player->getNetworkSession()->sendDataPacket(PlaySoundPacket::create('random.orb', $position->x, $position->y, $position->z, 1.0, 1.0, null));

        LobbyHud::showBossBar($player);
        $online = $this->getServer()->getOnlinePlayers();
        foreach ($online as $viewer) {
            LobbyHud::showSidebar($viewer, count($online));
        }

        if (count($player->getInventory()->getContents()) === 0) {
            $player->getInventory()->addItem(VanillaItems::BREAD()->setCount(3));
        }

        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(static function () use ($player): void {
            if ($player->isConnected()) {
                $player->sendTip('Need help? Type /menu');
            }
        }), 40);
    }

    /**
     * Everything 20 blocks or more east of spawn is the arena.
     */
    public function onMove(PlayerMoveEvent $event): void
    {
        $arenaStart = $event->getPlayer()->getWorld()->getSpawnLocation()->x + 20;
        if ($event->getFrom()->x < $arenaStart && $event->getTo()->x >= $arenaStart) {
            $event->getPlayer()->sendMessage(TextFormat::GOLD . 'You entered the arena.');
        }
    }

    /**
     * @priority HIGH
     */
    public function onBreak(BlockBreakEvent $event): void
    {
        if (!$event->getPlayer()->hasPermission('hello.build')) {
            $event->cancel();
            $event->getPlayer()->sendMessage(TextFormat::RED . 'You cannot build here.');
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        if (!$sender instanceof Player) {
            $sender->sendMessage('Run this command in game.');

            return true;
        }

        switch ($command->getName()) {
            case 'heal':
                $sender->setHealth($sender->getMaxHealth());
                $sender->getWorld()->addSound($sender->getPosition(), new XpLevelUpSound(30));
                $sender->sendMessage(TextFormat::GREEN . 'You have been healed.');

                return true;
            case 'menu':
                $sender->sendForm(new MenuForm());

                return true;
        }

        return false;
    }
}
