<?php

declare(strict_types=1);

namespace Example\HelloWorld;

use pocketmine\network\mcpe\protocol\BossEventPacket;
use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;

/**
 * A sidebar and a boss bar, the way most lobby plugins draw them.
 */
final class LobbyHud
{
    private const OBJECTIVE = 'hello.lobby';

    public static function showSidebar(Player $player, int $online): void
    {
        $session = $player->getNetworkSession();
        $session->sendDataPacket(RemoveObjectivePacket::create(self::OBJECTIVE));
        $session->sendDataPacket(SetDisplayObjectivePacket::create(
            SetDisplayObjectivePacket::DISPLAY_SLOT_SIDEBAR,
            self::OBJECTIVE,
            TextFormat::BOLD . TextFormat::GOLD . 'HelloWorld',
            'dummy',
            SetDisplayObjectivePacket::SORT_ORDER_ASCENDING,
        ));

        $entries = [];
        foreach (["Hi, {$player->getName()}", "Online: $online"] as $score => $line) {
            $entry = new ScorePacketEntry();
            $entry->objectiveName = self::OBJECTIVE;
            $entry->scoreboardId = $score + 1;
            $entry->score = $score;
            $entry->type = ScorePacketEntry::TYPE_FAKE_PLAYER;
            $entry->customName = TextFormat::WHITE . $line;
            $entries[] = $entry;
        }
        $session->sendDataPacket(SetScorePacket::create(SetScorePacket::TYPE_CHANGE, $entries));
    }

    public static function showBossBar(Player $player): void
    {
        $player->getNetworkSession()->sendDataPacket(
            BossEventPacket::show($player->getId(), TextFormat::AQUA . 'Welcome to HelloWorld', 1.0),
        );
    }
}
