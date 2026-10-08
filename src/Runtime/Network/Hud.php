<?php

declare(strict_types=1);

namespace Golem\Runtime\Network;

use pocketmine\network\mcpe\protocol\BossEventPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\utils\TextFormat;

/**
 * Rebuilds what a client shows on screen (sidebar scoreboard, boss bar) by replaying
 * the packets it received, in order.
 *
 * @internal
 */
final class Hud
{
    /**
     * @param list<ClientboundPacket> $packets
     * @return array{title: string, lines: list<string>}|null
     */
    public static function sidebar(array $packets): ?array
    {
        $sidebar = null; // objective shown in the sidebar: name, title, sort order
        /** @var array<string, array<int, array{int, string}>> $scores scores by objective, then scoreboard id */
        $scores = [];

        foreach ($packets as $packet) {
            if ($packet instanceof SetDisplayObjectivePacket && $packet->displaySlot === SetDisplayObjectivePacket::DISPLAY_SLOT_SIDEBAR) {
                $sidebar = ['name' => $packet->objectiveName, 'title' => $packet->displayName, 'descending' => $packet->sortOrder === SetDisplayObjectivePacket::SORT_ORDER_DESCENDING];
                $scores[$packet->objectiveName] = [];
            } elseif ($packet instanceof RemoveObjectivePacket) {
                unset($scores[$packet->objectiveName]);
                if ($sidebar !== null && $sidebar['name'] === $packet->objectiveName) {
                    $sidebar = null;
                }
            } elseif ($packet instanceof SetScorePacket) {
                foreach ($packet->entries as $entry) {
                    if (self::isRemoval($packet, $entry)) {
                        unset($scores[$entry->objectiveName][$entry->scoreboardId]);
                    } else {
                        $scores[$entry->objectiveName][$entry->scoreboardId] = [$entry->score, $entry->customName ?? ''];
                    }
                }
            }
        }

        if ($sidebar === null) {
            return null;
        }

        $lines = array_values($scores[$sidebar['name']] ?? []);
        usort($lines, static fn (array $a, array $b) => $sidebar['descending'] ? $b[0] <=> $a[0] : $a[0] <=> $b[0]);

        return [
            'title' => TextFormat::clean($sidebar['title']),
            'lines' => array_map(static fn (array $line) => TextFormat::clean($line[1]), $lines),
        ];
    }

    /**
     * PocketMine-MP 5 flags a whole SetScorePacket as a removal (its type property is 1);
     * newer protocols, used by some forks, flag each entry instead. Read whichever is there,
     * without referring to constants one of them does not have.
     */
    private static function isRemoval(SetScorePacket $packet, ScorePacketEntry $entry): bool
    {
        $packetFields = get_object_vars($packet);
        if (array_key_exists('type', $packetFields)) {
            return $packetFields['type'] === 1;
        }
        $perEntry = ScorePacketEntry::class . '::TYPE_REMOVE';

        return defined($perEntry) && $entry->type === constant($perEntry);
    }

    /**
     * The most recently shown boss bar that is still visible.
     *
     * @param list<ClientboundPacket> $packets
     * @return array{title: string, progress: float}|null
     */
    public static function bossBar(array $packets): ?array
    {
        /** @var array<int, array{title: string, progress: float}> $bars */
        $bars = [];

        foreach ($packets as $packet) {
            if (!$packet instanceof BossEventPacket) {
                continue;
            }
            $id = $packet->bossActorUniqueId;
            switch ($packet->eventType) {
                case BossEventPacket::TYPE_SHOW:
                    unset($bars[$id]); // re-showing moves it to the top
                    $bars[$id] = ['title' => $packet->title, 'progress' => $packet->healthPercent];
                    break;
                case BossEventPacket::TYPE_HIDE:
                    unset($bars[$id]);
                    break;
                case BossEventPacket::TYPE_TITLE:
                    if (isset($bars[$id])) {
                        $bars[$id]['title'] = $packet->title;
                    }
                    break;
                case BossEventPacket::TYPE_HEALTH_PERCENT:
                    if (isset($bars[$id])) {
                        $bars[$id]['progress'] = $packet->healthPercent;
                    }
                    break;
            }
        }

        if ($bars === []) {
            return null;
        }
        $bar = $bars[array_key_last($bars)];

        return ['title' => TextFormat::clean($bar['title']), 'progress' => $bar['progress']];
    }
}
