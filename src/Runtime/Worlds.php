<?php

declare(strict_types=1);

namespace Golem\Runtime;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\Filesystem;
use pocketmine\world\generator\Flat;
use pocketmine\world\World;
use pocketmine\world\WorldCreationOptions;

/**
 * Throwaway worlds, so a test can start from untouched terrain.
 *
 * @internal
 */
final class Worlds
{
    private ?World $original = null;

    private ?World $fresh = null;

    private int $counter = 0;

    public function __construct(private readonly PluginBase $plugin)
    {
    }

    /**
     * Creates a new superflat world and makes it the default one, where golems spawn.
     * Replaces the fresh world of the current test, if it already had one.
     */
    public function fresh(): World
    {
        $this->discard();

        $manager = $this->plugin->getServer()->getWorldManager();
        $this->original = $manager->getDefaultWorld();

        $name = 'golem-fresh-' . ++$this->counter;
        $options = WorldCreationOptions::create()->setGeneratorClass(Flat::class);
        // Spawn chunks are generated when the first golem asks for them.
        if (!$manager->generateWorld($name, $options, false)) {
            throw new \RuntimeException("Could not create the world \"$name\"");
        }
        $world = $manager->getWorldByName($name) ?? throw new \RuntimeException("The world \"$name\" did not load");

        $manager->setDefaultWorld($world);
        $this->fresh = $world;

        return $world;
    }

    /**
     * Restores the original default world and deletes the fresh one.
     * Called after the test's golems are gone.
     */
    public function discard(): void
    {
        $fresh = $this->fresh;
        if ($fresh === null) {
            return;
        }
        $this->fresh = null;

        $manager = $this->plugin->getServer()->getWorldManager();
        if ($this->original !== null && $this->original->isLoaded()) {
            $manager->setDefaultWorld($this->original);
        }

        $path = $fresh->getProvider()->getPath();
        $manager->unloadWorld($fresh, true);
        Filesystem::recursiveUnlink($path);
    }
}
