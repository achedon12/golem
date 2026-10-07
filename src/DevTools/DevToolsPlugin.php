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

    protected function onEnable(): void
    {
        $this->golems = new GolemFactory($this);
        $this->getServer()->getCommandMap()->register('golem', new GolemCommand($this, $this->golems));
    }

    protected function onDisable(): void
    {
        if (isset($this->golems)) {
            $this->golems->despawnAll();
        }
    }
}
