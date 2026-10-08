<?php

declare(strict_types=1);

namespace Golem\DevTools;

use Golem\Runtime\Fuzz\Literal;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerEntityInteractEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemHeldEvent;
use pocketmine\event\player\PlayerJumpEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\player\PlayerToggleSneakEvent;
use pocketmine\event\player\PlayerToggleSprintEvent;
use pocketmine\event\server\CommandEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\player\Player;
use pocketmine\Server;

/**
 * Records what real players do on a development server, and writes it as a Golem test
 * that replays it: /golem record <player...>, then /golem record stop.
 */
final class Recorder implements Listener
{
    /** a new waypoint once a player moved this far from the last one */
    private const WAYPOINT_DISTANCE = 2.0;

    /** waits longer than this are shortened: standing still rarely matters */
    private const MAX_WAIT_TICKS = 100;

    /** @var array<string, string> lowercase player name => variable in the test */
    private array $players = [];

    /** @var array<string, string> lowercase player name => name as it was */
    private array $names = [];

    /** @var list<string> setup lines, before the first action */
    private array $setup = [];

    /** @var list<array{tick: int, code: string}> */
    private array $actions = [];

    /** @var array<string, Vector3> where each player was last recorded */
    private array $waypoints = [];

    private int $startTick = 0;

    public function __construct(private readonly Server $server)
    {
    }

    public function isRecording(): bool
    {
        return $this->players !== [];
    }

    /**
     * @param list<Player> $players
     */
    public function start(array $players): void
    {
        $this->players = [];
        $this->names = [];
        $this->setup = [];
        $this->actions = [];
        $this->waypoints = [];
        $this->startTick = $this->server->getTick();

        foreach ($players as $player) {
            $key = strtolower($player->getName());
            $this->names[$key] = $player->getName();
            $this->players[$key] = $this->uniqueVariable($player->getName());
        }
        foreach ($players as $player) {
            $variable = $this->variable($player);
            $position = $player->getPosition();
            if ($this->server->isOp($player->getName())) {
                $this->setup[] = $variable . '->op();';
            }
            $this->setup[] = sprintf('%s->gamemode(GameMode::%s);', $variable, $player->getGamemode()->name);
            $this->setup[] = sprintf('%s->teleport(%s);', $variable, self::vector($position));
            $this->waypoints[strtolower($player->getName())] = $position->asVector3();
        }
    }

    /**
     * Stops recording and returns the test.
     *
     * @return array{class: string, code: string, actions: int}
     */
    public function stop(string $class): array
    {
        $names = array_values($this->names);
        $variables = array_values($this->players);
        $actions = $this->actions;
        $setup = $this->setup;
        $this->players = [];

        $lines = [
            sprintf('[%s] = yield $this->golems([%s]);', implode(', ', $variables), implode(', ', array_map(Literal::of(...), $names))),
            ...$setup,
            '',
        ];
        $previous = $this->startTick;
        $ticks = 0;
        foreach ($actions as $action) {
            $wait = min(self::MAX_WAIT_TICKS, $action['tick'] - $previous);
            if ($wait > 0) {
                $lines[] = "yield \$this->wait($wait);";
                $ticks += $wait;
            }
            $previous = $action['tick'];
            $lines[] = $action['code'];
        }
        $lines[] = '';
        $lines[] = '// TODO: assert what should have happened';

        $body = implode('', array_map(static fn (string $line) => $line === '' ? "\n" : "        $line\n", $lines));
        $uses = ['Golem\\Attribute\\Timeout', 'Golem\\TestCase', 'pocketmine\\math\\Vector3', 'pocketmine\\player\\GameMode'];
        $timeout = $ticks * 2 + 200; // golems may walk slower than the players did

        $code = "<?php\n\ndeclare(strict_types=1);\n\n"
            . implode('', array_map(static fn (string $use) => "use $use;\n", $uses))
            . "\n/**\n"
            . sprintf(" * Recorded with /golem record on %s: %s.\n", date('Y-m-d H:i'), implode(', ', $names))
            . " *\n"
            . " * Replays what they did, with the same pauses. Move it to your tests folder, set its\n"
            . " * namespace, then add the assertions of what should happen.\n"
            . " */\n"
            . "final class $class extends TestCase\n{\n"
            . "    #[Timeout($timeout)]\n"
            . "    public function testRecordedSession(): \\Generator\n    {\n"
            . $body
            . "    }\n}\n";

        return ['class' => $class, 'code' => $code, 'actions' => count($actions)];
    }

    /**
     * @priority MONITOR
     * @handleCancelled
     */
    public function onChat(PlayerChatEvent $event): void
    {
        $this->record($event->getPlayer(), '%s->chat(' . Literal::of($event->getMessage()) . ');');
    }

    /**
     * @priority MONITOR
     * @handleCancelled
     */
    public function onCommand(CommandEvent $event): void
    {
        $sender = $event->getSender();
        $line = $event->getCommand();
        if ($sender instanceof Player && !preg_match('/^golem(\s|$)/i', $line)) {
            $this->record($sender, '%s->command(' . Literal::of($line) . ');');
        }
    }

    /**
     * Forms and windows closed by the client: there is no event for these.
     *
     * @priority MONITOR
     * @handleCancelled
     */
    public function onPacket(DataPacketReceiveEvent $event): void
    {
        $player = $event->getOrigin()->getPlayer();
        $packet = $event->getPacket();
        if ($player === null) {
            return;
        }
        if ($packet instanceof ModalFormResponsePacket) {
            $data = $packet->formData !== null ? json_decode($packet->formData, true) : null;
            $this->record($player, '%s->submitForm(' . Literal::of($data) . ');');
        } elseif ($packet instanceof ContainerClosePacket && !$packet->server) {
            $this->record($player, '%s->closeWindow();');
        }
    }

    /**
     * @priority MONITOR
     * @handleCancelled
     */
    public function onBreak(BlockBreakEvent $event): void
    {
        $this->record($event->getPlayer(), '%s->breakBlock(' . self::vector($event->getBlock()->getPosition()) . ');');
    }

    /**
     * @priority MONITOR
     * @handleCancelled
     */
    public function onInteract(PlayerInteractEvent $event): void
    {
        if ($event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK) {
            $this->record($event->getPlayer(), sprintf('%%s->interactBlock(%s, %d);', self::vector($event->getBlock()->getPosition()), $event->getFace()));
        }
    }

    /**
     * @priority MONITOR
     * @handleCancelled
     */
    public function onInteractEntity(PlayerEntityInteractEvent $event): void
    {
        $target = $event->getEntity();
        if ($target instanceof Player && $this->isRecorded($target)) {
            $this->record($event->getPlayer(), '%s->interactEntity(' . $this->variable($target) . ');');
        }
    }

    /**
     * @priority MONITOR
     * @handleCancelled
     */
    public function onAttack(EntityDamageByEntityEvent $event): void
    {
        $attacker = $event->getDamager();
        $target = $event->getEntity();
        if ($attacker instanceof Player && $target instanceof Player && $this->isRecorded($target)) {
            $this->record($attacker, '%s->attack(' . $this->variable($target) . ');');
        }
    }

    /**
     * @priority MONITOR
     * @handleCancelled
     */
    public function onTransaction(InventoryTransactionEvent $event): void
    {
        $transaction = $event->getTransaction();
        $player = $transaction->getSource();
        $window = $player->getCurrentWindow();
        if ($window === null) {
            return;
        }
        foreach ($transaction->getActions() as $action) {
            if ($action instanceof SlotChangeAction && $action->getInventory() === $window) {
                $this->record($player, '%s->clickSlot(' . $action->getSlot() . ');');

                return; // one click moves items in several slots
            }
        }
    }

    /**
     * @priority MONITOR
     * @handleCancelled
     */
    public function onHeld(PlayerItemHeldEvent $event): void
    {
        $this->record($event->getPlayer(), '%s->player()->getInventory()->setHeldItemIndex(' . $event->getSlot() . ');');
    }

    /**
     * @priority MONITOR
     */
    public function onJump(PlayerJumpEvent $event): void
    {
        $this->record($event->getPlayer(), '%s->jump();');
    }

    /**
     * @priority MONITOR
     * @handleCancelled
     */
    public function onSneak(PlayerToggleSneakEvent $event): void
    {
        $this->record($event->getPlayer(), '%s->sneak(' . ($event->isSneaking() ? 'true' : 'false') . ');');
    }

    /**
     * @priority MONITOR
     * @handleCancelled
     */
    public function onSprint(PlayerToggleSprintEvent $event): void
    {
        $this->record($event->getPlayer(), '%s->sprint(' . ($event->isSprinting() ? 'true' : 'false') . ');');
    }

    /**
     * @priority MONITOR
     */
    public function onRespawn(PlayerRespawnEvent $event): void
    {
        $this->record($event->getPlayer(), '%s->respawn();');
    }

    /**
     * Movement becomes waypoints the golem walks to.
     *
     * @priority MONITOR
     */
    public function onMove(PlayerMoveEvent $event): void
    {
        $player = $event->getPlayer();
        if (!$this->isRecorded($player)) {
            return;
        }
        $to = $event->getTo();
        $key = strtolower($player->getName());
        $last = $this->waypoints[$key] ?? null;
        if ($last !== null && $last->distance($to) < self::WAYPOINT_DISTANCE) {
            return;
        }
        $this->waypoints[$key] = $to->asVector3();
        $this->record($player, '%s->walkTo(' . self::vector($to) . ');');
    }

    /**
     * @priority MONITOR
     */
    public function onQuit(PlayerQuitEvent $event): void
    {
        $this->record($event->getPlayer(), '%s->quit();');
    }

    private function isRecorded(Player $player): bool
    {
        return isset($this->players[strtolower($player->getName())]);
    }

    private function variable(Player $player): string
    {
        return $this->players[strtolower($player->getName())];
    }

    /**
     * @param string $code starting with %s for the player's variable
     */
    private function record(Player $player, string $code): void
    {
        if ($this->isRecorded($player)) {
            // not sprintf: the values in the code can contain % too
            $this->actions[] = ['tick' => $this->server->getTick(), 'code' => $this->variable($player) . substr($code, 2)];
        }
    }

    private function uniqueVariable(string $name): string
    {
        $base = lcfirst((string) preg_replace('/[^A-Za-z0-9_]/', '_', $name));
        if ($base === '' || ctype_digit($base[0])) {
            $base = 'player' . $base;
        }
        $variable = '$' . $base;
        for ($i = 2; in_array($variable, $this->players, true); $i++) {
            $variable = '$' . $base . $i;
        }

        return $variable;
    }

    private static function vector(Vector3 $position): string
    {
        $round = static fn (float $value): string => rtrim(rtrim(sprintf('%.1f', $value), '0'), '.');

        return sprintf('new Vector3(%s, %s, %s)', $round($position->x), $round($position->y), $round($position->z));
    }
}
