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

    public function __construct(
        private readonly PluginBase $plugin,
        private readonly string $pluginRoot,
    ) {
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
     * Loads a copy of a world folder and makes it the default world. The template is
     * left untouched; the copy is deleted with {@see discard()}.
     */
    public function fromTemplate(string $path): World
    {
        $source = str_starts_with($path, '/') ? $path : $this->pluginRoot . '/' . $path;
        if (!is_file($source . '/level.dat')) {
            throw new \RuntimeException("$path is not a world folder: it has no level.dat");
        }

        $this->discard();
        $manager = $this->plugin->getServer()->getWorldManager();
        $this->original = $manager->getDefaultWorld();

        $name = 'golem-template-' . ++$this->counter;
        self::copy($source, $this->plugin->getServer()->getDataPath() . 'worlds/' . $name);
        if (!$manager->loadWorld($name)) {
            throw new \RuntimeException("Could not load the world copied from $path");
        }
        $world = $manager->getWorldByName($name) ?? throw new \RuntimeException("The world copied from $path did not load");

        // load the area around spawn now, so tests can read and edit it before any golem joins
        $spawn = $world->getSpawnLocation();
        for ($x = -1; $x <= 1; $x++) {
            for ($z = -1; $z <= 1; $z++) {
                $world->loadChunk(($spawn->getFloorX() >> 4) + $x, ($spawn->getFloorZ() >> 4) + $z);
            }
        }

        $manager->setDefaultWorld($world);
        $this->fresh = $world;

        return $world;
    }

    private static function copy(string $from, string $to): void
    {
        @mkdir($to, 0777, true);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        /** @var \SplFileInfo $item */
        foreach ($items as $item) {
            $target = $to . substr($item->getPathname(), strlen($from));
            if ($item->isDir()) {
                @mkdir($target, 0777, true);
            } elseif (!copy($item->getPathname(), $target)) {
                throw new \RuntimeException("Could not copy {$item->getPathname()}");
            }
        }
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
