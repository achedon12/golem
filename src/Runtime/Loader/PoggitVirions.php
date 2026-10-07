<?php

declare(strict_types=1);

namespace Golem\Runtime\Loader;

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
    private const MAX_BYTES = 20 * 1024 * 1024;

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
        try {
            $body = $this->fetch($url);
        } catch (\RuntimeException $e) {
            if (is_file($file)) {
                return $file; // offline: a stale copy beats no copy
            }
            throw new \RuntimeException("Could not download the virion $source $version from Poggit: {$e->getMessage()}", 0, $e);
        }

        @mkdir(dirname($file), 0777, true);
        $partial = $file . '.part';
        file_put_contents($partial, $body);
        try {
            self::assertIsVirion($partial, "$source $version");
        } catch (\Throwable $e) {
            @unlink($partial);
            throw $e;
        }
        rename($partial, $file);

        return $file;
    }

    /**
     * Downloads over HTTPS only, with the certificate verified: the file is code that
     * will run. (PocketMine's own Internet helper does not verify certificates.)
     */
    private function fetch(string $url): string
    {
        $curl = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'golem (+https://github.com/achedon12/golem)',
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($curl, int $total, int $received): int => $received > self::MAX_BYTES ? 1 : 0,
        ];
        $caFile = ini_get('openssl.cafile');
        if (is_string($caFile) && $caFile !== '' && is_file($caFile)) {
            $options[CURLOPT_CAINFO] = $caFile;
        }
        curl_setopt_array($curl, $options);

        $body = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($body) || $code !== 200 || $body === '') {
            throw new \RuntimeException($error !== '' ? $error : "HTTP $code");
        }

        return $body;
    }

    /**
     * Refuses anything that is not a virion phar, so a broken or hijacked download
     * never reaches the cache.
     */
    private static function assertIsVirion(string $file, string $label): void
    {
        try {
            $phar = new \Phar($file);
            $isVirion = isset($phar['virion.yml']);
        } catch (\Throwable) {
            $isVirion = false;
        }
        if (!$isVirion) {
            throw new \RuntimeException("The download of $label is not a virion phar");
        }
    }
}
