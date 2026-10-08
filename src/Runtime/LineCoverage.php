<?php

declare(strict_types=1);

namespace Golem\Runtime;

/**
 * Which lines of the plugin's code ran, with pcov or Xdebug, whichever the server's PHP has.
 *
 * Only the main thread is covered: code running in async tasks is not.
 *
 * @internal
 */
final class LineCoverage
{
    public static function available(): bool
    {
        return function_exists('pcov\start') || function_exists('xdebug_start_code_coverage');
    }

    /**
     * @param list<string> $directories only these are covered: covering PocketMine too would
     *                                  slow the server down enough to break tests
     */
    public static function start(array $directories): void
    {
        if (function_exists('pcov\start')) {
            \pcov\start(); // pcov.directory is set when PHP starts
        } elseif (function_exists('xdebug_start_code_coverage')) {
            $prefixes = [];
            foreach ($directories as $directory) {
                $prefixes[] = rtrim($directory, '/') . '/';
                $real = realpath($directory);
                if ($real !== false) {
                    $prefixes[] = rtrim($real, '/') . '/';
                }
            }
            xdebug_set_filter(XDEBUG_FILTER_CODE_COVERAGE, XDEBUG_PATH_INCLUDE, array_values(array_unique($prefixes)));
            xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);
        }
    }

    /**
     * The executable lines of the files under $directory, with whether each one ran.
     *
     * @return array<string, array<int, int>> path relative to $directory => line => 1 if it ran, else 0
     */
    public static function collect(string $directory): array
    {
        if (function_exists('pcov\collect')) {
            \pcov\stop();
            /** @var array<string, array<int, int>> $raw line => hits, -1 when it did not run */
            $raw = \pcov\collect(\pcov\inclusive);
        } elseif (function_exists('xdebug_get_code_coverage')) {
            /** @var array<string, array<int, int>> $raw line => 1 when it ran, -1 when not, -2 when it cannot run */
            $raw = xdebug_get_code_coverage();
            xdebug_stop_code_coverage();
        } else {
            return [];
        }

        $root = rtrim(str_replace('\\', '/', (string) realpath($directory)), '/') . '/';
        $lines = [];
        foreach ($raw as $file => $fileLines) {
            $path = str_replace('\\', '/', (string) realpath($file));
            if (!str_starts_with($path, $root)) {
                continue;
            }
            $relative = substr($path, strlen($root));
            foreach ($fileLines as $line => $hits) {
                if ($hits !== -2) {
                    $lines[$relative][$line] = max($lines[$relative][$line] ?? 0, $hits > 0 ? 1 : 0);
                }
            }
            ksort($lines[$relative]);
        }
        ksort($lines);

        return $lines;
    }
}
