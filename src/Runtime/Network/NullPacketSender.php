<?php

declare(strict_types=1);

namespace Golem\Runtime\Network;

use pocketmine\network\mcpe\PacketSender;

/**
 * Discards encoded batches: a golem reads what the server sent from its {@see Inbox}.
 */
final class NullPacketSender implements PacketSender
{
    public function send(string $payload, bool $immediate, ?int $receiptId): void
    {
    }

    public function close(string $reason = 'unknown reason'): void
    {
    }
}
