<?php

declare(strict_types=1);

namespace Golem\Runtime\Loader;

use pocketmine\utils\Internet;

/**
 * Finds the virions a plugin declares in its .poggit.yml and downloads them from
 * Poggit, the way Poggit itself would when building the plugin.
 *
 *     projects:
 *       MyPlugin:
 *         libs:
 *           - src: muqsit/InvMenu/InvMenu
 *             version: ^4.6.0
 *           - src: libs/MyLocalVirion
 *             vendor: raw
 */
final class PoggitVirions
{
    private const DOWNLOAD = 'https://poggit.pmmp.io/v.dl/%s/%s/%s/%s';
    private const CACHE_SECONDS = 86400;

    public function __construct(private readonly string $cacheDirectory)
    {
    }

    /**
     * @return list<string> paths of virion folders and phars
     */
    public function resolve(string $manifestPath, string $pluginRoot): array
    {
        $manifest = yaml_parse_file($manifestPath);
        $projects = is_array($manifest) && is_array($manifest['projects'] ?? null) ? $manifest['projects'] : [];
        $base = dirname($manifestPath);
        $project = $this->project($projects, $base, $pluginRoot);
        if ($project === null) {
            return [];
        }

        $virions = [];
        foreach ((array) ($project['libs'] ?? []) as $lib) {
            if (!is_array($lib) || !is_string($lib['src'] ?? null)) {
                continue;
            }
            $virions[] = ($lib['vendor'] ?? 'poggit') === 'raw'
                ? $base . '/' . ltrim($lib['src'], '/')
                : $this->download($lib['src'], is_scalar($lib['version'] ?? null) ? (string) $lib['version'] : '*');
        }

        return $virions;
    }

    /**
     * The project whose path is the plugin folder; with a single project, that one.
     *
     * @param array<mixed> $projects
     * @return array<mixed>|null
     */
    private function project(array $projects, string $base, string $pluginRoot): ?array
    {
        $relative = trim(substr((string) realpath($pluginRoot), strlen((string) realpath($base))), '/');
        foreach ($projects as $project) {
            if (is_array($project) && trim((string) ($project['path'] ?? ''), '/') === $relative) {
                return $project;
            }
        }

        $only = count($projects) === 1 ? reset($projects) : null;

        return is_array($only) ? $only : null;
    }

    private function download(string $source, string $version): string
    {
        $parts = explode('/', trim($source, '/'));
        if (count($parts) !== 3) {
            throw new \RuntimeException("Unsupported virion source \"$source\" in .poggit.yml, expected owner/repository/name");
        }
        [$owner, $repository, $name] = $parts;

        $file = sprintf('%s/virions/%s.phar', $this->cacheDirectory, sha1("$owner/$repository/$name@$version"));
        if (is_file($file) && filemtime($file) > time() - self::CACHE_SECONDS) {
            return $file;
        }

        $url = sprintf(self::DOWNLOAD, rawurlencode($owner), rawurlencode($repository), rawurlencode($name), rawurlencode($version));
        $result = Internet::getURL($url, 30, [], $error);
        if ($result === null || $result->getCode() !== 200 || $result->getBody() === '') {
            if (is_file($file)) {
                return $file; // offline: a stale copy beats no copy
            }
            throw new \RuntimeException("Could not download the virion $source $version from Poggit" . (is_string($error) ? ": $error" : ''));
        }

        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, $result->getBody());

        return $file;
    }
}
