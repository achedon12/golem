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

        /** @var array{events: string, tests: string, subjects: string, subject: string, filter: ?string, files?: list<string>|null, repeat?: int, seed?: int|null, pluginRoot: string, cache: string, virions: list<string>, poggit: ?string, updateSnapshots?: bool, ci?: bool, coverage?: bool, fuzz?: array{seed: int, seconds: int, golems: int}|null, bench?: array{steps: list<int>, seconds: int}|null} $config */
        $config = json_decode((string) file_get_contents($configPath), true, flags: JSON_THROW_ON_ERROR);
        $this->events = new EventLog($config['events']);
        if (($config['coverage'] ?? false) && LineCoverage::available()) {
            // before the plugin loads, to cover onLoad() and onEnable() too; the plugin is loaded
            // through a link in the subjects folder, so its code has paths from both
            LineCoverage::start([$config['pluginRoot'] . '/src', $config['subjects']]);
        }

        try {
            $this->loadVirions($config);
        } catch (\Throwable $e) {
            $this->abort('Could not load the virions: ' . $e->getMessage());

            return;
        }
        $this->loadSubjects($config['subjects']);

        Runtime::install(new Runtime(
            $this,
            // collecting coverage slows joins down a lot
            new GolemFactory($this, ($config['coverage'] ?? false) ? 600 : 200),
            new Clock($this),
            new Worlds($this, $config['pluginRoot']),
            $config['subject'],
            $config['updateSnapshots'] ?? false,
            $config['ci'] ?? false,
            $config['coverage'] ?? false,
        ));

        // The first tick only happens once every plugin is enabled and the world is ready.
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(fn () => $this->begin($config)), 1);
    }

    /**
     * Loads the virions listed in composer.json and in the plugin's .poggit.yml, before
     * the plugin itself so its classes can use them from onLoad().
     *
     * @param array{events: string, tests: string, subjects: string, subject: string, filter: ?string, files?: list<string>|null, repeat?: int, seed?: int|null, pluginRoot: string, cache: string, virions: list<string>, poggit: ?string, updateSnapshots?: bool, ci?: bool, coverage?: bool, fuzz?: array{seed: int, seconds: int, golems: int}|null, bench?: array{steps: list<int>, seconds: int}|null} $config
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
     * @param array{events: string, tests: string, subjects: string, subject: string, filter: ?string, files?: list<string>|null, repeat?: int, seed?: int|null, pluginRoot: string, cache: string, virions: list<string>, poggit: ?string, updateSnapshots?: bool, ci?: bool, coverage?: bool, fuzz?: array{seed: int, seconds: int, golems: int}|null, bench?: array{steps: list<int>, seconds: int}|null} $config
     */
    private function begin(array $config): void
    {
        $runtime = Runtime::get();
        $subject = $this->getServer()->getPluginManager()->getPlugin($runtime->subject);
        if ($subject === null || !$subject->isEnabled()) {
            $this->abort("The plugin \"{$runtime->subject}\" failed to load or enable. Its error is in the server log above.");

            return;
        }

        $fuzz = $config['fuzz'] ?? null;
        if ($fuzz !== null) {
            (new Fuzz\FuzzRunner($runtime, $subject, $this->events, $fuzz['seed'], $fuzz['seconds'] * 20, $fuzz['golems'], function (): void {
                $this->events->write('end');
                $this->getServer()->shutdown();
            }))->start();

            return;
        }

        $bench = $config['bench'] ?? null;
        if ($bench !== null) {
            (new Bench\BenchRunner($runtime, $subject, $this->events, $bench['steps'], $bench['seconds'] * 20, function (): void {
                $this->events->write('end');
                $this->getServer()->shutdown();
            }))->start();

            return;
        }

        try {
            $tests = TestOrder::arrange(TestDiscovery::discover($config['tests'], $config['filter'], $config['files'] ?? null), $config['repeat'] ?? 1, $config['seed'] ?? null);
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

        $coverage = null;
        if ($config['coverage'] ?? false) {
            $coverage = new Coverage($this, $subject);
            $coverage->install();
        }

        $testsDirectory = str_replace('\\', '/', (string) realpath($config['tests']));
        $runner = new TestRunner($runtime, $tests, $this->events, $testsDirectory, function () use ($coverage, $config): void {
            if ($coverage !== null) {
                $lines = LineCoverage::available() ? LineCoverage::collect($config['pluginRoot'] . '/src') : null;
                $this->events->write('coverage', $coverage->report() + ['lines' => $lines]);
            }
            $this->events->write('end');
            $this->getServer()->shutdown();
        });

        if ($coverage !== null && LineCoverage::available()) {
            $this->warmUp($runner->start(...));
        } else {
            $runner->start();
        }
    }

    /**
     * Prepares the chunks around the spawn before the first test. Under Xdebug, the async
     * workers generating them start very slowly, and the first golem would time out joining.
     */
    private function warmUp(\Closure $then): void
    {
        $world = $this->getServer()->getWorldManager()->getDefaultWorld();
        if ($world === null) {
            $then();

            return;
        }
        $spawn = $world->getSpawnLocation();
        $radius = $this->getServer()->getViewDistance();
        $promises = [];
        for ($x = -$radius; $x <= $radius; $x++) {
            for ($z = -$radius; $z <= $radius; $z++) {
                $promises[] = $world->orderChunkPopulation(($spawn->getFloorX() >> 4) + $x, ($spawn->getFloorZ() >> 4) + $z, null);
            }
        }
        // counted before waiting: chunks already there complete at once
        $pending = count($promises);
        $done = function () use (&$pending, $then): void {
            if (--$pending === 0) {
                $then();
            }
        };
        foreach ($promises as $promise) {
            $promise->onCompletion($done, $done);
        }
    }

    private function abort(string $message): void
    {
        $this->events->write('abort', ['message' => $message]);
        $this->getServer()->shutdown();
    }
}
