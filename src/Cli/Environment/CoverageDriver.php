<?php

declare(strict_types=1);

namespace Golem\Cli\Environment;

/**
 * Finds a way to collect line coverage with the server's PHP: pcov or Xdebug when it
 * already loads one, else the Xdebug build that ships, disabled, with PocketMine's PHP.
 */
final class CoverageDriver
{
    /**
     * @param string $sourceDirectory the plugin's src/, the only code pcov has to cover
     * @return list<string>|null the options to start PHP with, or null when there is no driver
     */
    public static function phpOptions(string $php, string $sourceDirectory): ?array
    {
        $modules = (string) shell_exec(escapeshellarg($php) . ' -m 2>/dev/null');
        if (preg_match('/^pcov$/mi', $modules) === 1) {
            return ['-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . $sourceDirectory];
        }
        if (preg_match('/^xdebug$/mi', $modules) === 1) {
            return ['-d', 'xdebug.mode=coverage'];
        }

        // bin/php7/bin/php → bin/php7/lib/php/extensions/no-debug-zts-*/xdebug.so
        $xdebug = glob(dirname($php, 2) . '/lib/php/extensions/*/xdebug.so') ?: [];
        if ($xdebug === []) {
            return null;
        }

        return ['-d', 'zend_extension=' . $xdebug[0], '-d', 'xdebug.mode=coverage'];
    }
}
