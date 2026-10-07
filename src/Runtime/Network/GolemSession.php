<?php

declare(strict_types=1);

namespace Golem\Runtime\Network;

use pmmp\encoding\ByteBufferWriter;
use pocketmine\form\Form;
use pocketmine\lang\Translatable;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\handler\InGamePacketHandler;
use pocketmine\network\mcpe\handler\PacketHandler;
use pocketmine\network\mcpe\handler\PreSpawnPacketHandler;
use pocketmine\network\mcpe\handler\ResourcePacksPacketHandler;
use pocketmine\network\mcpe\handler\SpawnResponsePacketHandler;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\RequestChunkRadiusPacket;
use pocketmine\network\mcpe\protocol\ResourcePackClientResponsePacket;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\network\mcpe\protocol\SetLocalPlayerAsInitializedPacket;
use pocketmine\network\mcpe\StandardEntityEventBroadcaster;
use pocketmine\network\mcpe\StandardPacketBroadcaster;
use pocketmine\player\PlayerInfo;
use pocketmine\Server;

/**
 * A network session with no network behind it.
 *
 * PocketMine believes a real client is connected: the session goes through the
 * regular login, resource pack and spawn sequences. Instead of being encoded and
 * sent over RakNet, everything the server sends is recorded so tests can inspect it,
 * and the client half of the handshake is played back automatically.
 */
final class GolemSession extends NetworkSession
{
    private const VIEW_DISTANCE = 4;

    private static ?RecordingBroadcaster $broadcaster = null;

    private readonly Inbox $inbox;

    /** @var list<ServerboundPacket> client packets waiting to be handled on the next tick */
    private array $outgoing = [];

    /** @var (\Closure(): void)|null */
    private ?\Closure $onSpawned;

    /**
     * @param \Closure(): void $onSpawned
     */
    public function __construct(Server $server, \Closure $onSpawned)
    {
        $this->inbox = new Inbox();
        $this->onSpawned = $onSpawned;

        $network = $server->getNetwork();
        $typeConverter = TypeConverter::getInstance();
        // Shared by every golem, so a broadcast to several golems is still encoded once.
        $broadcaster = self::$broadcaster ??= new RecordingBroadcaster(new StandardPacketBroadcaster($server));

        parent::__construct(
            $server,
            $network->getSessionManager(),
            PacketPool::getInstance(),
            new NullPacketSender(),
            $broadcaster,
            new StandardEntityEventBroadcaster($broadcaster, $typeConverter),
            ZlibCompressor::getInstance(),
            $typeConverter,
            '127.0.0.1',
            19132,
        );
    }

    /**
     * Skips the encrypted Xbox Live handshake: the server sees an offline-mode login
     * that has already been accepted.
     */
    public function login(PlayerInfo $info): void
    {
        (new \ReflectionProperty(NetworkSession::class, 'info'))->setValue($this, $info);
        (new \ReflectionMethod(NetworkSession::class, 'onServerLoginSuccess'))->invoke($this);
    }

    public function inbox(): Inbox
    {
        return $this->inbox;
    }

    /**
     * Plays the client side of each handshake phase as soon as the server enters it.
     * Packets are queued rather than handled immediately, because handlers are swapped
     * from deep inside other handlers and re-entering them is not safe.
     */
    public function setHandler(?PacketHandler $handler): void
    {
        parent::setHandler($handler);

        match (true) {
            $handler instanceof ResourcePacksPacketHandler => $this->queue(
                ResourcePackClientResponsePacket::create(ResourcePackClientResponsePacket::STATUS_COMPLETED, []),
            ),
            $handler instanceof PreSpawnPacketHandler => $this->queue(
                RequestChunkRadiusPacket::create(self::VIEW_DISTANCE, self::VIEW_DISTANCE),
            ),
            $handler instanceof SpawnResponsePacketHandler => $this->queue(
                SetLocalPlayerAsInitializedPacket::create($this->getPlayer()?->getId() ?? 0),
            ),
            $handler instanceof InGamePacketHandler => $this->spawned(),
            default => null,
        };
    }

    /**
     * Delivers queued client packets. Called once per server tick.
     */
    public function pump(): void
    {
        $packets = $this->outgoing;
        $this->outgoing = [];
        foreach ($packets as $packet) {
            if (!$this->isConnected()) {
                return;
            }
            $writer = new ByteBufferWriter();
            $packet->encode($writer);
            $this->handleDataPacket($packet, $writer->getData());
        }
    }

    public function sendDataPacket(ClientboundPacket $packet, bool $immediate = false): bool
    {
        $this->inbox->recordPacket($packet);

        return parent::sendDataPacket($packet, $immediate);
    }

    public function onChatMessage(Translatable|string $message): void
    {
        $this->inbox->record(Inbox::CHAT, $this->translate($message));
        parent::onChatMessage($message);
    }

    public function onJukeboxPopup(Translatable|string $message): void
    {
        $this->inbox->record(Inbox::POPUP, $this->translate($message));
        parent::onJukeboxPopup($message);
    }

    public function onPopup(string $message): void
    {
        $this->inbox->record(Inbox::POPUP, $message);
        parent::onPopup($message);
    }

    public function onTip(string $message): void
    {
        $this->inbox->record(Inbox::TIP, $message);
        parent::onTip($message);
    }

    public function onTitle(string $title): void
    {
        $this->inbox->record(Inbox::TITLE, $title);
        parent::onTitle($title);
    }

    public function onSubTitle(string $subtitle): void
    {
        $this->inbox->record(Inbox::SUBTITLE, $subtitle);
        parent::onSubTitle($subtitle);
    }

    public function onActionBar(string $actionBar): void
    {
        $this->inbox->record(Inbox::ACTION_BAR, $actionBar);
        parent::onActionBar($actionBar);
    }

    public function onToastNotification(string $title, string $body): void
    {
        $this->inbox->record(Inbox::TOAST, $title . "\n" . $body);
        parent::onToastNotification($title, $body);
    }

    public function onFormSent(int $id, Form $form): bool
    {
        $this->inbox->recordForm($id, $form);

        return parent::onFormSent($id, $form);
    }

    public function onCloseAllForms(): void
    {
        $this->inbox->clearForms();
        parent::onCloseAllForms();
    }

    private function queue(ServerboundPacket $packet): void
    {
        $this->outgoing[] = $packet;
    }

    private function spawned(): void
    {
        $onSpawned = $this->onSpawned;
        $this->onSpawned = null;
        if ($onSpawned !== null) {
            $onSpawned();
        }
    }

    private function translate(Translatable|string $message): string
    {
        if ($message instanceof Translatable) {
            $player = $this->getPlayer();

            return $player !== null ? $player->getLanguage()->translate($message) : $message->getText();
        }

        return $message;
    }
}
