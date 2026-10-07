<?php

declare(strict_types=1);

namespace Example\HelloWorld;

use example\libgreeting\Greeting;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
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
    }

    public function onJoin(PlayerJoinEvent $event): void
    {
        $player = $event->getPlayer();
        $player->sendMessage(TextFormat::GREEN . Greeting::welcome($player->getName()));
        $player->sendTitle(TextFormat::GOLD . 'Hello!');
        $position = $player->getPosition();
        $player->getNetworkSession()->sendDataPacket(PlaySoundPacket::create('random.orb', $position->x, $position->y, $position->z, 1.0, 1.0, null));

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
