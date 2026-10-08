<?php

declare(strict_types=1);

namespace Golem\Runtime;

use Golem\Runtime\Loader\FolderPluginLoader;
use Golem\Runtime\Loader\PoggitVirions;
use Golem\Runtime\Loader\VirionLoader;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginEnableOrder;
use pocketmine\scheduler\ClosureTask;
use pocketmine\VersionInfo;

/**
 * The plugin the CLI injects into the test server.
 *
 * It loads the plugin under test from its source folder, waits for the server to
 * finish starting, runs the tests, then stops the server.
 *
 * @internal
 */
final class GolemPlugin extends PluginBase
{
    public const ENV_CONFIG = 'GOLEM_RUNTIME_CONFIG';

    private EventLog $events;

    protected function onEnable(): void
    {
        $configPath = getenv(self::ENV_CONFIG);
        if ($configPath === false || !is_file($configPath)) {
            $this->getLogger()->warning('Golem only runs through its CLI (vendor/bin/golem); staying idle.');

            return;
        }

        /** @var array{events: string, tests: string, subjects: string, subject: string, filter: ?string, pluginRoot: string, cache: string, virions: list<string>, poggit: ?string, updateSnapshots?: bool, ci?: bool} $config */
        $config = json_decode((string) file_get_contents($configPath), true, flags: JSON_THROW_ON_ERROR);
        $this->events = new EventLog($config['events']);

        try {
            $this->loadVirions($config);
        } catch (\Throwable $e) {
            $this->abort('Could not load the virions: ' . $e->getMessage());

            return;
        }
        $this->loadSubjects($config['subjects']);

        Runtime::install(new Runtime(
            $this,
            new GolemFactory($this),
            new Clock($this),
            new Worlds($this, $config['pluginRoot']),
            $config['subject'],
            $config['updateSnapshots'] ?? false,
            $config['ci'] ?? false,
        ));

        // The first tick only happens once every plugin is enabled and the world is ready.
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(fn () => $this->begin($config)), 1);
    }

    /**
     * Loads the virions listed in composer.json and in the plugin's .poggit.yml, before
     * the plugin itself so its classes can use them from onLoad().
     *
     * @param array{events: string, tests: string, subjects: string, subject: string, filter: ?string, pluginRoot: string, cache: string, virions: list<string>, poggit: ?string, updateSnapshots?: bool, ci?: bool} $config
     */
    private function loadVirions(array $config): void
    {
        $paths = $config['virions'];
        if ($config['poggit'] !== null) {
            array_push($paths, ...(new PoggitVirions($config['cache']))->resolve($config['poggit'], $config['pluginRoot']));
        }

        $loader = new VirionLoader($this->getServer()->getLoader());
        foreach ($paths as $path) {
            $this->getLogger()->info('Loaded virion ' . $loader->load($path));
        }
    }

    /**
     * Loads the plugins under test with the folder loader. Plugins set to load at
     * POSTWORLD are enabled later by the server itself, STARTUP ones right now.
     */
    private function loadSubjects(string $directory): void
    {
        $manager = $this->getServer()->getPluginManager();
        $manager->registerInterface(new FolderPluginLoader($this->getServer()->getLoader()));

        foreach ($manager->loadPlugins($directory) as $plugin) {
            if ($plugin->getDescription()->getOrder() === PluginEnableOrder::STARTUP) {
                $manager->enablePlugin($plugin);
            }
        }
    }

    /**
     * @param array{events: string, tests: string, subjects: string, subject: string, filter: ?string, pluginRoot: string, cache: string, virions: list<string>, poggit: ?string, updateSnapshots?: bool, ci?: bool} $config
     */
    private function begin(array $config): void
    {
        $runtime = Runtime::get();
        $subject = $this->getServer()->getPluginManager()->getPlugin($runtime->subject);
        if ($subject === null || !$subject->isEnabled()) {
            $this->abort("The plugin \"{$runtime->subject}\" failed to load or enable. Its error is in the server log above.");

            return;
        }

        try {
            $tests = TestDiscovery::discover($config['tests'], $config['filter']);
        } catch (\Throwable $e) {
            $this->abort(sprintf('Could not load the tests: %s (%s:%d)', $e->getMessage(), $e->getFile(), $e->getLine()));

            return;
        }

        $this->events->write('start', [
            'count' => count($tests),
            'pocketmine' => VersionInfo::VERSION()->getFullVersion(),
            'php' => PHP_VERSION,
            'plugin' => $subject->getName() . ' ' . $subject->getDescription()->getVersion(),
        ]);

        $testsDirectory = str_replace('\\', '/', (string) realpath($config['tests']));
        (new TestRunner($runtime, $tests, $this->events, $testsDirectory, function (): void {
            $this->events->write('end');
            $this->getServer()->shutdown();
        }))->start();
    }

    private function abort(string $message): void
    {
        $this->events->write('abort', ['message' => $message]);
        $this->getServer()->shutdown();
    }
}
