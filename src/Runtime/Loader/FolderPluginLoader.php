<?php

declare(strict_types=1);

namespace Golem\Runtime\Loader;

use pocketmine\plugin\PluginDescription;
use pocketmine\plugin\PluginLoader;
use pocketmine\thread\ThreadSafeClassLoader;

/**
 * Loads a plugin straight from its source folder (plugin.yml + src/), so the code
 * under test is the code on disk: no phar to build, and stack traces point at your files.
 */
final class FolderPluginLoader implements PluginLoader
{
    public function __construct(private readonly ThreadSafeClassLoader $loader)
    {
    }

    public function canLoadPlugin(string $path): bool
    {
        return is_dir($path) && is_file($path . '/plugin.yml') && is_dir($path . '/src');
    }

    public function loadPlugin(string $file): void
    {
        $description = $this->getPluginDescription($file);
        if ($description !== null) {
            $this->loader->addPath($description->getSrcNamespacePrefix(), $file . '/src');
        }
    }

    public function getPluginDescription(string $file): ?PluginDescription
    {
        $yaml = @file_get_contents($file . '/plugin.yml');

        return $yaml === false ? null : new PluginDescription($yaml);
    }

    public function getAccessProtocol(): string
    {
        return '';
    }
}
