<?php

declare(strict_types=1);

namespace Golem\DevTools;

use Golem\Runtime\GolemFactory;
use pocketmine\plugin\PluginBase;

/**
 * Golem as a server plugin: /golem spawns and drives simulated players on a
 * development server, with the same engine as the test framework.
 */
final class DevToolsPlugin extends PluginBase
{
    private GolemFactory $golems;

    /** @var array<string, true> lower-case names of every golem this server ever spawned */
    private array $knownGolems = [];

    protected function onEnable(): void
    {
        $file = $this->getDataFolder() . 'golems.json';
        $names = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        foreach (is_array($names) ? $names : [] as $name) {
            if (is_string($name)) {
                $this->knownGolems[$name] = true;
            }
        }

        $this->golems = new GolemFactory($this);
        $this->getServer()->getCommandMap()->register('golem', new GolemCommand($this, $this->golems));
    }

    /**
     * Whether a name belongs to a real player of this server: an operator, or someone
     * with saved player data that Golem did not create. A golem with such a name would
     * act with that player's rights and data.
     */
    public function isRealPlayer(string $name): bool
    {
        if (isset($this->knownGolems[strtolower($name)])) {
            return false;
        }
        $server = $this->getServer();

        return $server->isOp($name) || $server->hasOfflinePlayerData($name);
    }

    public function rememberGolem(string $name): void
    {
        if (isset($this->knownGolems[strtolower($name)])) {
            return;
        }
        $this->knownGolems[strtolower($name)] = true;
        @mkdir($this->getDataFolder(), 0777, true);
        file_put_contents($this->getDataFolder() . 'golems.json', json_encode(array_keys($this->knownGolems), JSON_PRETTY_PRINT));
    }

    protected function onDisable(): void
    {
        if (isset($this->golems)) {
            $this->golems->despawnAll();
        }
    }
}
