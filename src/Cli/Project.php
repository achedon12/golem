<?php

declare(strict_types=1);

namespace Golem\Cli;

/**
 * The plugin being tested: its plugin.yml, and the Golem settings from composer.json.
 *
 *     "extra": {
 *         "golem": {
 *             "tests": "tests",
 *             "pocketmine": "5.44.3",
 *             "plugins": ["libs/SomeDependency.phar"],
 *             "virions": ["libs/SomeVirion"]
 *         }
 *     }
 */
final class Project
{
    /**
     * @param list<string> $extraPlugins absolute paths
     * @param list<string> $virions absolute paths
     */
    private function __construct(
        public readonly string $root,
        public readonly string $name,
        public readonly string $namespace,
        public readonly string $testsDirectory,
        public readonly string $pocketmineVersion,
        public readonly array $extraPlugins,
        public readonly array $virions,
        public readonly ?string $poggitManifest,
    ) {
    }

    public static function load(string $path, ?string $tests, ?string $pocketmine): self
    {
        $root = realpath($path);
        if ($root === false || !is_dir($root)) {
            throw new UserError("The plugin folder \"$path\" does not exist.");
        }

        $manifest = $root . '/plugin.yml';
        if (!is_file($manifest)) {
            throw new UserError("No plugin.yml found in $root. Run Golem from your plugin's folder, or pass --path.");
        }
        if (!is_dir($root . '/src')) {
            throw new UserError("$root has no src/ folder. Golem loads plugins from source.");
        }

        $yaml = self::readManifest((string) file_get_contents($manifest));
        $name = $yaml['name'] ?? throw new UserError('plugin.yml has no "name".');
        $main = $yaml['main'] ?? throw new UserError('plugin.yml has no "main".');
        $namespace = $yaml['src-namespace-prefix'] ?? substr($main, 0, (int) strrpos($main, '\\'));

        $settings = self::composerSettings($root);
        $testsDirectory = $tests ?? (is_string($settings['tests'] ?? null) ? $settings['tests'] : 'tests');
        $testsPath = self::absolute($root, $testsDirectory);

        $extraPlugins = [];
        foreach ((array) ($settings['plugins'] ?? []) as $plugin) {
            if (!is_string($plugin)) {
                continue;
            }
            $absolute = self::absolute($root, $plugin);
            if (!file_exists($absolute)) {
                throw new UserError("The extra plugin \"$plugin\" listed in composer.json does not exist.");
            }
            $extraPlugins[] = $absolute;
        }

        $virions = [];
        foreach ((array) ($settings['virions'] ?? []) as $virion) {
            if (!is_string($virion)) {
                continue;
            }
            $absolute = self::absolute($root, $virion);
            if (!file_exists($absolute)) {
                throw new UserError("The virion \"$virion\" listed in composer.json does not exist.");
            }
            $virions[] = $absolute;
        }

        return new self(
            $root,
            $name,
            $namespace,
            $testsPath,
            $pocketmine ?? (is_string($settings['pocketmine'] ?? null) ? $settings['pocketmine'] : 'latest'),
            $extraPlugins,
            $virions,
            self::findPoggitManifest($root),
        );
    }

    /**
     * The .poggit.yml of the plugin: in its folder or, for repositories holding several
     * plugins, in a parent folder of the same git repository. Never outside of it, since
     * the virions it lists get downloaded and run.
     */
    private static function findPoggitManifest(string $root): ?string
    {
        if (is_file($root . '/.poggit.yml')) {
            return $root . '/.poggit.yml';
        }

        $repository = self::gitRoot($root);
        if ($repository === null) {
            return null;
        }
        for ($directory = dirname($root); str_starts_with($directory, $repository); $directory = dirname($directory)) {
            if (is_file($directory . '/.poggit.yml')) {
                return $directory . '/.poggit.yml';
            }
            if ($directory === $repository) {
                break;
            }
        }

        return null;
    }

    private static function gitRoot(string $directory): ?string
    {
        for ($depth = 0; $depth < 6; $depth++) {
            if (file_exists($directory . '/.git')) {
                return $directory;
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                return null;
            }
            $directory = $parent;
        }

        return null;
    }

    /**
     * Reads the top-level scalar keys of plugin.yml. Uses ext-yaml when available;
     * the fallback only understands "key: value" lines, which is all Golem needs.
     *
     * @return array<string, string>
     */
    private static function readManifest(string $yaml): array
    {
        if (function_exists('yaml_parse')) {
            $parsed = @yaml_parse($yaml);
            if (is_array($parsed)) {
                return array_map(
                    static fn (mixed $value) => is_scalar($value) ? (string) $value : '',
                    array_filter($parsed, static fn (mixed $value, mixed $key) => is_string($key) && is_scalar($value), ARRAY_FILTER_USE_BOTH),
                );
            }
        }

        $values = [];
        foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+):\s*(.+?)\s*$/', $line, $match) === 1) {
                $values[$match[1]] = trim($match[2], '"\'');
            }
        }

        return $values;
    }

    /**
     * @return array<mixed>
     */
    private static function composerSettings(string $root): array
    {
        $file = $root . '/composer.json';
        if (!is_file($file)) {
            return [];
        }
        $composer = json_decode((string) file_get_contents($file), true);
        $settings = is_array($composer) ? ($composer['extra']['golem'] ?? []) : [];

        return is_array($settings) ? $settings : [];
    }

    private static function absolute(string $root, string $path): string
    {
        $isAbsolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

        return $isAbsolute ? $path : $root . '/' . $path;
    }
}
