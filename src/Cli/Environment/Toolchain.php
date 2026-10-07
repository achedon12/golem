<?php

declare(strict_types=1);

namespace Golem\Cli\Environment;

use Golem\Cli\Filesystem;
use Golem\Cli\Output;
use Golem\Cli\UserError;

/**
 * Provides the two things a PocketMine server needs: the PocketMine-MP phar, and the
 * custom PHP build it runs on. Both are downloaded once from the official pmmp
 * releases and cached.
 */
final class Toolchain
{
    private const PHP_VERSION = '8.4';
    private const PHP_RELEASE = 'https://github.com/pmmp/PHP-Binaries/releases/download/pm5-php-%1$s-latest/PHP-%1$s-%2$s-PM5.tar.gz';
    private const PHAR_RELEASE = 'https://github.com/pmmp/PocketMine-MP/releases/download/%s/PocketMine-MP.phar';
    private const TAG_PAGE = 'https://github.com/pmmp/PocketMine-MP/releases/latest';

    public function __construct(
        private readonly string $cacheDirectory,
        private readonly Downloader $downloader,
        private readonly Output $output,
    ) {
    }

    public static function defaultCacheDirectory(): string
    {
        $override = getenv('GOLEM_CACHE_DIR');
        if (is_string($override) && $override !== '') {
            return $override;
        }
        $xdg = getenv('XDG_CACHE_HOME');
        if (is_string($xdg) && $xdg !== '') {
            return $xdg . '/golem';
        }
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();

        return $home . (PHP_OS_FAMILY === 'Darwin' ? '/Library/Caches/golem' : '/.cache/golem');
    }

    /**
     * @return array{string, string} the resolved version and the phar path
     */
    public function pocketmine(string $version): array
    {
        if ($version === 'latest') {
            $version = $this->latestPocketMineVersion();
        }
        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new UserError("\"$version\" is not a PocketMine-MP version. Use \"latest\" or something like \"5.44.3\".");
        }
        if (!str_starts_with($version, '5.')) {
            throw new UserError("Golem supports PocketMine-MP 5. Version $version is not supported.");
        }

        $phar = "{$this->cacheDirectory}/pocketmine/$version/PocketMine-MP.phar";
        if (!is_file($phar)) {
            $this->output->writeln("  <dim>Downloading PocketMine-MP {$version}…</>");
            $this->downloader->download(sprintf(self::PHAR_RELEASE, $version), $phar);
        }

        return [$version, $phar];
    }

    /**
     * The php executable of the official PocketMine PHP build, ready to use.
     */
    public function php(): string
    {
        $platform = self::platform();
        $root = "{$this->cacheDirectory}/php/" . self::PHP_VERSION . "-$platform";
        $binary = "$root/bin/php7/bin/php";
        if (is_file($binary)) {
            return $binary;
        }

        $this->output->writeln("  <dim>Downloading PHP " . self::PHP_VERSION . " for PocketMine ($platform), only needed once…</>");
        $archive = "$root.tar.gz";
        $this->downloader->download(sprintf(self::PHP_RELEASE, self::PHP_VERSION, $platform), $archive);

        $staging = "$root.staging";
        Filesystem::remove($staging);
        mkdir($staging, 0777, true);
        $this->extract($archive, $staging);
        @unlink($archive);

        $stagedBinary = "$staging/bin/php7/bin/php";
        if (!is_file($stagedBinary)) {
            throw new UserError('The PHP archive does not contain bin/php7/bin/php');
        }
        chmod($stagedBinary, 0755);
        Filesystem::remove($root);
        rename($staging, $root);
        self::fixOpcachePath($root);

        return $binary;
    }

    private function latestPocketMineVersion(): string
    {
        $cached = "{$this->cacheDirectory}/pocketmine/latest.json";
        if (is_file($cached) && filemtime($cached) > time() - 6 * 3600) {
            $data = json_decode((string) file_get_contents($cached), true);
            if (is_array($data) && is_string($data['version'] ?? null)) {
                return $data['version'];
            }
        }

        $location = $this->downloader->resolveRedirect(self::TAG_PAGE);
        if (preg_match('#/tag/v?([0-9.]+)$#', $location, $match) !== 1) {
            throw new UserError("Could not work out the latest PocketMine-MP version from $location. Pass --pocketmine=<version>.");
        }
        @mkdir(dirname($cached), 0777, true);
        file_put_contents($cached, json_encode(['version' => $match[1]]));

        return $match[1];
    }

    private static function platform(): string
    {
        $arch = strtolower(php_uname('m'));

        return match (true) {
            PHP_OS_FAMILY === 'Linux' && in_array($arch, ['x86_64', 'amd64'], true) => 'Linux-x86_64',
            PHP_OS_FAMILY === 'Darwin' && in_array($arch, ['arm64', 'aarch64'], true) => 'MacOS-arm64',
            PHP_OS_FAMILY === 'Darwin' => 'MacOS-x86_64',
            PHP_OS_FAMILY === 'Windows' => throw new UserError('Golem runs on Linux and macOS. On Windows, run it from WSL.'),
            default => throw new UserError("No PocketMine PHP build exists for " . PHP_OS_FAMILY . " $arch."),
        };
    }

    private function extract(string $archive, string $destination): void
    {
        $command = sprintf('tar -xzf %s -C %s 2>&1', escapeshellarg($archive), escapeshellarg($destination));
        exec($command, $output, $status);
        if ($status !== 0) {
            throw new UserError("Could not extract $archive: " . implode("\n", $output));
        }
    }

    /**
     * The archive's php.ini loads opcache from the path it was built at. Point it at
     * the extension that was actually extracted, like the official installer does.
     */
    private static function fixOpcachePath(string $root): void
    {
        $ini = "$root/bin/php7/bin/php.ini";
        $extensions = glob("$root/bin/php7/lib/php/extensions/*/opcache.so") ?: [];
        if (!is_file($ini) || $extensions === []) {
            return;
        }
        $content = (string) file_get_contents($ini);
        $content = (string) preg_replace('/^zend_extension=.*opcache\.so$/m', 'zend_extension=' . $extensions[0], $content);
        file_put_contents($ini, $content);
    }
}
