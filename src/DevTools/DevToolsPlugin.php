<?php

declare(strict_types=1);

namespace Golem\DevTools;

use Golem\Runtime\GolemFactory;
use Golem\Runtime\Network\GolemSession;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDataSaveEvent;
use pocketmine\plugin\PluginBase;

/**
 * Golem as a server plugin: /golem spawns and drives simulated players on a
 * development server, with the same engine as the test framework.
 */
final class DevToolsPlugin extends PluginBase implements Listener
{
    private GolemFactory $golems;

    protected function onEnable(): void
    {
        $this->golems = new GolemFactory($this);
        $this->getServer()->getCommandMap()->register('golem', new GolemCommand($this, $this->golems));
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    protected function onDisable(): void
    {
        if (isset($this->golems)) {
            $this->golems->despawnAll();
        }
    }

    /**
     * Golems leave no player data behind, so their names never look like real players
     * and a golem can always come back under the same name.
     *
     * @priority MONITOR
     */
    public function onPlayerDataSave(PlayerDataSaveEvent $event): void
    {
        if ($event->getPlayer()?->getNetworkSession() instanceof GolemSession) {
            $event->cancel();
        }
    }

    /**
     * Whether a name belongs to a real player of this server: an operator, or someone
     * with saved player data. A golem with such a name would act with that player's
     * rights and data.
     */
    public function isRealPlayer(string $name): bool
    {
        $server = $this->getServer();

        return $server->isOp($name) || $server->hasOfflinePlayerData($name);
    }
}
