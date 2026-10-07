<?php

declare(strict_types=1);

namespace Golem\Runtime;

use Golem\Golem;
use Golem\Runtime\Coroutine\Deferred;
use Golem\Runtime\Network\GolemSession;
use pocketmine\entity\Skin;
use pocketmine\player\PlayerInfo;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use Ramsey\Uuid\Uuid;

/**
 * Spawns golems and keeps their fake connections ticking.
 */
final class GolemFactory
{
    private const SPAWN_TIMEOUT_TICKS = 200;

    /** @var array<int, GolemSession> */
    private array $sessions = [];

    /** @var array<int, Deferred<Golem>> spawns still in progress, by session */
    private array $joining = [];

    /** @var list<Golem> golems spawned since the last {@see despawnAll()} */
    private array $golems = [];

    /** @var TaskHandler<ClosureTask>|null */
    private ?TaskHandler $pump = null;

    private int $counter = 0;

    public function __construct(private readonly PluginBase $plugin)
    {
    }

    /**
     * @return Deferred<Golem> resolves once the golem is in the world, after every join event fired
     */
    public function spawn(?string $name = null): Deferred
    {
        $name ??= 'Golem' . ++$this->counter;
        $server = $this->plugin->getServer();
        /** @var Deferred<Golem> $deferred */
        $deferred = new Deferred();

        $session = null;
        $session = new GolemSession($server, function () use (&$session, $deferred): void {
            /** @var GolemSession $session */
            $player = $session->getPlayer();
            if ($player === null) {
                $deferred->reject(new \RuntimeException('The golem spawned without a player'));

                return;
            }
            unset($this->joining[spl_object_id($session)]);
            $golem = new Golem($player, $session, $this->plugin);
            $this->golems[] = $golem;
            $deferred->resolve($golem);
        });
        $this->sessions[spl_object_id($session)] = $session;
        $this->joining[spl_object_id($session)] = $deferred;
        $this->startPumping();

        $session->login(new PlayerInfo(
            $name,
            Uuid::uuid4(),
            new Skin('Standard_Custom', str_repeat("\x00", 64 * 64 * 4)),
            'en_US',
            [],
        ));

        $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(static function () use ($deferred, $name): void {
            $deferred->reject(new \RuntimeException(sprintf(
                'Golem "%s" did not finish joining within %d ticks. Was it kicked by a plugin or a full server?',
                $name,
                self::SPAWN_TIMEOUT_TICKS,
            )));
        }), self::SPAWN_TIMEOUT_TICKS);

        return $deferred;
    }

    /**
     * Disconnects every golem spawned so far and revokes their op status, so the next
     * test starts on an empty server.
     */
    public function despawnAll(): void
    {
        $server = $this->plugin->getServer();
        foreach ($this->golems as $golem) {
            // ops are saved by name in ops.txt and would leak into the next test
            $server->removeOp($golem->name());
        }
        foreach ($this->sessions as $session) {
            $player = $session->getPlayer();
            if ($player !== null && $player->isConnected()) {
                $player->disconnect('Test finished');
            } elseif ($session->isConnected()) {
                $session->disconnect('Test finished');
            }
        }
        $this->sessions = [];
        $this->joining = [];
        $this->golems = [];
    }

    private function startPumping(): void
    {
        if ($this->pump !== null) {
            return;
        }

        $this->pump = $this->plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            foreach ($this->sessions as $id => $session) {
                if (!$session->isConnected()) {
                    unset($this->sessions[$id]);
                    continue;
                }
                try {
                    $session->pump();
                } catch (\Throwable $e) {
                    // Usually a plugin listener failing on join: fail the test that
                    // spawned this golem instead of crashing the whole server.
                    unset($this->sessions[$id]);
                    $deferred = $this->joining[$id] ?? null;
                    unset($this->joining[$id]);
                    try {
                        $session->disconnect('Golem crashed while joining');
                    } catch (\Throwable) {
                        // the session is half-built; the original error matters more
                    }
                    if ($deferred === null) {
                        throw $e;
                    }
                    $deferred->reject($e);
                }
            }
            foreach ($this->golems as $golem) {
                try {
                    $golem->movement()->tick();
                } catch (\Throwable $e) {
                    if (!$golem->movement()->abort($e)) {
                        $this->plugin->getLogger()->logException($e);
                    }
                }
            }
        }), 1);
    }
}
