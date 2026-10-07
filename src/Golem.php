<?php

declare(strict_types=1);

namespace Golem;

use Golem\Runtime\Coroutine\Deferred;
use Golem\Runtime\Movement;
use Golem\Runtime\Runtime;
use Golem\Runtime\Network\GolemSession;
use Golem\Runtime\Network\Hud;
use Golem\Runtime\Network\Inbox;
use pocketmine\entity\Entity;
use pocketmine\form\Form;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\inventory\transaction\TransactionException;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\DisconnectPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;

/**
 * A simulated player.
 *
 * As far as PocketMine and your plugin are concerned, a golem is a real {@see Player}:
 * it logs in, fires every join event, takes damage, owns an inventory and can be
 * kicked. Golems act through methods like {@see chat()} or {@see breakBlock()}, and
 * remember everything the server sends them so you can assert on it.
 */
final class Golem
{
    /** Walking speed of a player, in blocks per second. */
    public const WALK_SPEED = 4.317;

    /** Sprinting speed of a player, in blocks per second. */
    public const SPRINT_SPEED = 5.612;

    private readonly Movement $movement;

    /**
     * @internal golems are created with {@see TestCase::golem()}
     */
    public function __construct(
        private readonly Player $player,
        private readonly GolemSession $session,
        private readonly Plugin $owner,
    ) {
        $this->movement = new Movement($player, $this);
    }

    public function name(): string
    {
        return $this->player->getName();
    }

    /**
     * The underlying PocketMine player, for anything this class does not wrap.
     */
    public function player(): Player
    {
        return $this->player;
    }

    public function isOnline(): bool
    {
        return $this->player->isConnected();
    }

    /**
     * What the disconnection screen said (colour codes removed), or null while the
     * golem is still connected. Covers kicks, bans and {@see quit()}.
     */
    public function disconnectReason(): ?string
    {
        $packets = $this->packets(DisconnectPacket::class);
        $last = $packets === [] ? null : $packets[array_key_last($packets)];

        return $last === null ? null : TextFormat::clean($last->message ?? '');
    }

    // ---------------------------------------------------------------- acting

    /**
     * Sends a chat message, exactly as if it was typed in the chat box.
     * Messages starting with "/" run as commands.
     */
    public function chat(string $message): void
    {
        $this->player->chat($message);
    }

    /**
     * Runs a command as this golem. The leading slash is optional.
     *
     * @return bool whether a command with that name exists
     */
    public function command(string $commandLine): bool
    {
        return $this->player->getServer()->dispatchCommand($this->player, ltrim($commandLine, '/'));
    }

    /**
     * Makes this golem a server operator.
     */
    public function op(): self
    {
        $this->player->getServer()->addOp($this->name());

        return $this;
    }

    public function deop(): self
    {
        $this->player->getServer()->removeOp($this->name());

        return $this;
    }

    /**
     * Grants (or explicitly denies) a single permission.
     */
    public function grant(string $permission, bool $value = true): self
    {
        $this->player->addAttachment($this->owner, $permission, $value);

        return $this;
    }

    public function deny(string $permission): self
    {
        return $this->grant($permission, false);
    }

    public function gamemode(GameMode $mode): self
    {
        $this->player->setGamemode($mode);

        return $this;
    }

    public function teleport(Vector3 $target): self
    {
        $this->player->teleport($target);

        return $this;
    }

    public function position(): Position
    {
        return $this->player->getPosition();
    }

    /**
     * Walks to a position, one step per tick like a real player: walls stop the golem,
     * it falls off ledges (and takes fall damage), and PlayerMoveEvent fires along the way.
     * The target's height is ignored; golems walk on whatever ground there is.
     *
     *     yield $steve->walkTo($this->spawn()->add(10, 0, 0));
     *
     * @return Deferred<Golem> resolves on arrival; fails if the golem stays stuck for a second
     */
    public function walkTo(Vector3 $target, float $blocksPerSecond = self::WALK_SPEED): Deferred
    {
        return $this->movement->walkTo($target, $blocksPerSecond);
    }

    /**
     * Walks a number of blocks from where the golem stands.
     *
     * @return Deferred<Golem>
     */
    public function walk(float $x, float $z, float $blocksPerSecond = self::WALK_SPEED): Deferred
    {
        return $this->walkTo($this->position()->add($x, 0, $z), $blocksPerSecond);
    }

    /**
     * Jumps (firing PlayerJumpEvent); the golem lands a few ticks later.
     *
     * @return bool false when the golem is not standing on the ground
     */
    public function jump(): bool
    {
        return $this->movement->jump();
    }

    public function sneak(bool $sneaking = true): self
    {
        $this->player->toggleSneak($sneaking);

        return $this;
    }

    public function sprint(bool $sprinting = true): self
    {
        $this->player->toggleSprint($sprinting);

        return $this;
    }

    /**
     * Adds items to the inventory.
     */
    public function give(Item ...$items): self
    {
        $this->player->getInventory()->addItem(...$items);

        return $this;
    }

    /**
     * Puts an item in the main hand.
     */
    public function hold(Item $item): self
    {
        $this->player->getInventory()->setItemInHand($item);

        return $this;
    }

    /**
     * Breaks a block, going through the same checks and events as survival mining.
     *
     * @return bool false if the break was cancelled or out of reach
     */
    public function breakBlock(Vector3 $position): bool
    {
        $this->player->lookAt($position->add(0.5, 0.5, 0.5));

        return $this->player->breakBlock($position);
    }

    /**
     * Right-clicks a block face, which places the held block or uses the held item on it.
     *
     * @param int $face one of the {@see Facing} constants
     */
    public function interactBlock(Vector3 $position, int $face = Facing::UP): bool
    {
        $this->player->lookAt($position->add(0.5, 0.5, 0.5));

        return $this->player->interactBlock($position, $face, new Vector3(0.5, 0.5, 0.5));
    }

    /**
     * Uses the held item in the air (eating, throwing, drawing a bow...).
     */
    public function useItem(): bool
    {
        return $this->player->useHeldItem();
    }

    /**
     * Hits an entity or another golem with the held item.
     */
    public function attack(Entity|self $target): bool
    {
        $entity = $target instanceof self ? $target->player() : $target;
        $this->player->lookAt($entity->getEyePos());

        return $this->player->attackEntity($entity);
    }

    /**
     * Right-clicks an entity or another golem (talking to an NPC, mounting, using an
     * item on a mob), firing PlayerEntityInteractEvent.
     *
     * @return bool whether the entity reacted to the interaction
     */
    public function interactEntity(Entity|self $target): bool
    {
        $entity = $target instanceof self ? $target->player() : $target;
        $clickPosition = $entity->getPosition()->add(0, $entity->getSize()->getHeight() / 2, 0);
        $this->player->lookAt($clickPosition);

        return $this->player->interactEntity($entity, $clickPosition);
    }

    public function isAlive(): bool
    {
        return $this->player->isAlive();
    }

    /**
     * Leaves the death screen, like pressing Respawn, firing PlayerRespawnEvent.
     *
     * @return bool false when the golem is not dead
     */
    public function respawn(): bool
    {
        if ($this->player->isAlive()) {
            return false;
        }
        $this->player->respawn();

        return true;
    }

    /**
     * Disconnects, as if the player closed the game.
     */
    public function quit(string $reason = 'Golem left'): void
    {
        if ($this->player->isConnected()) {
            $this->player->disconnect($reason);
        }
    }

    // ------------------------------------------------------------- receiving

    /**
     * Chat messages received, colour codes removed.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(TextFormat::clean(...), $this->session->inbox()->texts(Inbox::CHAT));
    }

    /**
     * Chat messages received, colour codes kept.
     *
     * @return list<string>
     */
    public function rawMessages(): array
    {
        return $this->session->inbox()->texts(Inbox::CHAT);
    }

    public function lastMessage(): ?string
    {
        $messages = $this->messages();

        return $messages === [] ? null : $messages[array_key_last($messages)];
    }

    /** @return list<string> */
    public function titles(): array
    {
        return $this->cleanTexts(Inbox::TITLE);
    }

    /** @return list<string> */
    public function subtitles(): array
    {
        return $this->cleanTexts(Inbox::SUBTITLE);
    }

    /** @return list<string> */
    public function actionBars(): array
    {
        return $this->cleanTexts(Inbox::ACTION_BAR);
    }

    /** @return list<string> */
    public function popups(): array
    {
        return $this->cleanTexts(Inbox::POPUP);
    }

    /** @return list<string> */
    public function tips(): array
    {
        return $this->cleanTexts(Inbox::TIP);
    }

    /**
     * Toast notifications, as "title\nbody".
     *
     * @return list<string>
     */
    public function toasts(): array
    {
        return $this->cleanTexts(Inbox::TOAST);
    }

    /**
     * The sidebar scoreboard as the golem sees it: its title and lines from top to
     * bottom, colour codes removed. Null when no sidebar is shown.
     *
     * @return array{title: string, lines: list<string>}|null
     */
    public function scoreboard(): ?array
    {
        return Hud::sidebar($this->session->inbox()->packets());
    }

    /**
     * The boss bar on the golem's screen (the latest one shown, if several), with its
     * progress from 0.0 to 1.0. Null when none is shown.
     *
     * @return array{title: string, progress: float}|null
     */
    public function bossBar(): ?array
    {
        return Hud::bossBar($this->session->inbox()->packets());
    }

    /**
     * Sounds the golem heard, in order: named sounds sent with a PlaySoundPacket
     * ("random.levelup", "mob.cat.meow") and built-in sound events played in the world
     * ("levelup", "break", "place"), like those of {@see \pocketmine\world\World::addSound()}.
     *
     * @return list<string>
     */
    public function sounds(): array
    {
        $sounds = [];
        foreach ($this->session->inbox()->packets() as $packet) {
            if ($packet instanceof PlaySoundPacket) {
                $sounds[] = $packet->soundName;
            } elseif ($packet instanceof LevelSoundEventPacket) {
                $sounds[] = $packet->sound;
            }
        }

        return $sounds;
    }

    /**
     * Every packet the server sent, optionally only those of one class.
     *
     * @template P of ClientboundPacket
     * @param class-string<P>|null $class
     * @return ($class is null ? list<ClientboundPacket> : list<P>)
     */
    public function packets(?string $class = null): array
    {
        $packets = $this->session->inbox()->packets();
        if ($class === null) {
            return $packets;
        }

        return array_values(array_filter($packets, static fn (ClientboundPacket $p) => $p instanceof $class));
    }

    /**
     * Forgets every message, title and packet received so far. Open forms are kept.
     */
    public function clearInbox(): self
    {
        $this->session->inbox()->clear();

        return $this;
    }

    // ---------------------------------------------------------- inventories

    /**
     * The inventory window open on the golem's screen (a chest, a menu), or null.
     */
    public function window(): ?Inventory
    {
        return $this->player->getCurrentWindow();
    }

    /**
     * Waits until a window is open and settled: menu libraries such as InvMenu send the
     * window several times while it is being displayed, and ignore clicks until then.
     *
     *     $steve->chat('/shop');
     *     $shop = yield $steve->waitForWindow();
     *
     * @return Deferred<Inventory>
     */
    public function waitForWindow(int $timeoutTicks = 60): Deferred
    {
        $server = $this->player->getServer();

        return Runtime::get()->clock->until(
            fn () => $this->window() !== null && $server->getTick() - $this->session->lastContainerOpenTick() >= 2 ? $this->window() : null,
            $timeoutTicks,
            "a window to open for {$this->name()}",
        );
    }

    /**
     * Clicks a slot of the open window: picks up its item, swapping with whatever the
     * cursor holds. This is a real inventory transaction, so InventoryTransactionEvent
     * fires and menu libraries such as InvMenu react exactly as for a player.
     *
     * @return bool false when the click was refused (cancelled by a plugin, or nothing
     *              to pick up with an empty cursor)
     */
    public function clickSlot(int $slot): bool
    {
        $window = $this->window() ?? throw new \LogicException("{$this->name()} has no window open");
        if (!$window->slotExists($slot)) {
            throw new \LogicException("The open window of {$this->name()} has no slot $slot");
        }

        $cursor = $this->player->getCursorInventory();
        $inSlot = $window->getItem($slot);
        $held = $cursor->getItem(0);
        if ($inSlot->isNull() && $held->isNull()) {
            return false;
        }

        $transaction = new InventoryTransaction($this->player, [
            new SlotChangeAction($window, $slot, $inSlot, $held),
            new SlotChangeAction($cursor, 0, $held, $inSlot),
        ]);
        try {
            $transaction->execute();
        } catch (TransactionException) {
            return false;
        }

        return true;
    }

    /**
     * Closes the open window, like pressing Escape. Whatever the cursor held goes back
     * to the inventory, as for a player.
     */
    public function closeWindow(): self
    {
        $this->player->removeCurrentWindow();

        return $this;
    }

    // ----------------------------------------------------------------- forms

    /**
     * The most recent form still waiting for an answer.
     */
    public function form(): ?Form
    {
        $forms = $this->session->inbox()->forms();

        return $forms === [] ? null : $forms[array_key_last($forms)];
    }

    /**
     * The open form as the client receives it: title, content, buttons or elements.
     *
     * @return array<string, mixed>
     */
    public function formData(): array
    {
        $form = $this->form() ?? throw new \LogicException("{$this->name()} has no open form");
        $data = json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }

    /**
     * Answers the open form with raw response data: a button index for menu forms,
     * a boolean for modal forms, a list of values for custom forms.
     */
    public function submitForm(mixed $data): self
    {
        $forms = $this->session->inbox()->forms();
        if ($forms === []) {
            throw new \LogicException("{$this->name()} has no open form to submit");
        }
        $id = array_key_last($forms);
        $this->session->inbox()->forgetForm($id);
        $this->player->onFormSubmit($id, $data);

        return $this;
    }

    /**
     * Clicks a button of the open menu form, by label (colour codes ignored) or index.
     */
    public function clickButton(string|int $button): self
    {
        if (is_int($button)) {
            return $this->submitForm($button);
        }

        $buttons = $this->formData()['buttons'] ?? [];
        foreach (is_array($buttons) ? $buttons : [] as $index => $candidate) {
            $text = is_array($candidate) && is_string($candidate['text'] ?? null) ? $candidate['text'] : '';
            if (TextFormat::clean($text) === TextFormat::clean($button)) {
                return $this->submitForm($index);
            }
        }

        throw new \LogicException("The open form of {$this->name()} has no button labelled \"$button\"");
    }

    /**
     * Closes the open form without answering it, like pressing the cross.
     */
    public function closeForm(): self
    {
        return $this->submitForm(null);
    }

    /**
     * @internal
     */
    public function movement(): Movement
    {
        return $this->movement;
    }

    /**
     * @internal
     */
    public function session(): GolemSession
    {
        return $this->session;
    }

    /**
     * @param Inbox::* $channel
     * @return list<string>
     */
    private function cleanTexts(string $channel): array
    {
        return array_map(TextFormat::clean(...), $this->session->inbox()->texts($channel));
    }
}
