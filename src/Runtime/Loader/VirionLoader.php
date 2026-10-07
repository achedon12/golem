<?php

declare(strict_types=1);

namespace Golem\Runtime\Loader;

use pocketmine\thread\ThreadSafeClassLoader;

/**
 * Makes virions (libraries bundled into plugins at build time) loadable while the
 * plugin runs from source.
 *
 * Poggit shades a virion into each plugin's namespace when it builds the phar, but the
 * source code refers to the virion's own namespace (its "antigen"), so that is the
 * namespace registered here.
 */
final class VirionLoader
{
    public function __construct(private readonly ThreadSafeClassLoader $loader)
    {
    }

    /**
     * @param string $path a virion folder (virion.yml + src/) or a virion phar
     * @return string the virion's name and version, for the log
     */
    public function load(string $path): string
    {
        $root = is_file($path) ? 'phar://' . realpath($path) : rtrim($path, '/');
        $manifest = @file_get_contents($root . '/virion.yml');
        if ($manifest === false) {
            throw new \RuntimeException("$path is not a virion: it has no virion.yml");
        }

        $data = yaml_parse($manifest);
        $antigen = is_array($data) && is_string($data['antigen'] ?? null) ? trim($data['antigen'], '\\') : '';
        if ($antigen === '') {
            throw new \RuntimeException("The virion.yml of $path has no antigen");
        }

        $this->loader->addPath($antigen . '\\', $root . '/src/' . str_replace('\\', '/', $antigen));

        $name = is_string($data['name'] ?? null) ? $data['name'] : $antigen;
        $version = is_scalar($data['version'] ?? null) ? (string) $data['version'] : '?';

        return "$name $version";
    }
}
