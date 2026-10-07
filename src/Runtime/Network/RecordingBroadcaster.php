<?php

declare(strict_types=1);

namespace Golem\Runtime\Network;

use pocketmine\network\mcpe\PacketBroadcaster;

/**
 * Packets broadcast by the world (sounds, particles, entity animations...) skip
 * {@see GolemSession::sendDataPacket()} and go straight to the batch encoder. This
 * broadcaster records them for golems before passing them on.
 */
final class RecordingBroadcaster implements PacketBroadcaster
{
    public function __construct(private readonly PacketBroadcaster $inner)
    {
    }

    public function broadcastPackets(array $recipients, array $packets): void
    {
        foreach ($recipients as $recipient) {
            if ($recipient instanceof GolemSession) {
                foreach ($packets as $packet) {
                    $recipient->inbox()->recordPacket($packet);
                }
            }
        }

        $this->inner->broadcastPackets($recipients, $packets);
    }
}
