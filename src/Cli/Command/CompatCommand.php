<?php

declare(strict_types=1);

namespace Golem\Cli\Command;

use Golem\Cli\Environment\Downloader;
use Golem\Cli\Environment\Toolchain;
use Golem\Cli\Options;
use Golem\Cli\Output;
use Golem\Cli\Report\CompactReporter;
use Golem\Cli\Report\MigrationReport;
use Golem\Cli\UserError;

/**
 * golem compat <plugin...>: runs the tests alone, then with other plugins loaded next to the
 * plugin, and reports what changes: tests that break, commands another plugin takes.
 */
final class CompatCommand
{
    public function __construct(
        private readonly Output $output,
        private readonly string $golemSource,
    ) {
    }

    public function execute(Options $options): int
    {
        if ($options->arguments === []) {
            throw new UserError('Name the plugins to test with: golem compat <plugin.phar | folder | PoggitName>...');
        }
        $plugins = array_map($this->resolve(...), $options->arguments);
        $names = array_map(static fn (string $path) => (string) preg_replace('/(_v[\d.]+)?\.phar$/', '', basename($path)), $plugins);

        $run = new RunCommand($this->output, $this->golemSource);
        $project = $run->project($options);
        $reporter = new CompactReporter($this->output);

        $alone = $run->runSuite($options, $project, $project->pocketmineVersion, $reporter);
        $alone->label = 'alone';
        $with = $run->runSuite($options, $project->withExtraPlugins($plugins), $project->pocketmineVersion, $reporter);
        $with->label = 'with ' . implode(', ', $names);

        $migration = new MigrationReport($alone, $with);
        $migration->render($this->output);

        $newConflicts = array_values(array_filter($with->conflicts, static fn (array $c) => !in_array($c, $alone->conflicts, true)));
        foreach ($newConflicts as $conflict) {
            $this->output->writeln('  <yellow>! ' . Output::escape(\Golem\Cli\Report\ConsoleReporter::conflict($conflict)) . '</>');
        }
        if ($newConflicts !== []) {
            $this->output->writeln();
        }
        $compatible = $migration->regressions() === 0 && $newConflicts === [] && !$with->crashed && $with->abortReason === null;
        $this->output->writeln($compatible
            ? sprintf('  <green>%s works the same with %s.</>', Output::escape($project->name), Output::escape(implode(', ', $names)))
            : sprintf('  <red>%s does not work the same with %s.</>', Output::escape($project->name), Output::escape(implode(', ', $names))));
        $this->output->writeln();

        $summary = getenv('GITHUB_STEP_SUMMARY');
        if (is_string($summary) && $summary !== '') {
            file_put_contents($summary, $migration->markdown(), FILE_APPEND);
        }

        return $compatible ? 0 : 1;
    }

    /**
     * A plugin given on the command line: a phar or a plugin folder on disk, else the name of
     * a plugin of the Poggit archive, downloaded once into Golem's cache.
     */
    private function resolve(string $plugin): string
    {
        $path = realpath($plugin);
        if ($path !== false) {
            return $path;
        }
        if (preg_match('/^[A-Za-z0-9_-]{2,64}$/', $plugin) !== 1) {
            throw new UserError("\"$plugin\" is neither a file nor the name of a Poggit plugin.");
        }

        $cache = Toolchain::defaultCacheDirectory() . '/plugins';
        $file = "$cache/$plugin.phar";
        if (!is_file($file)) {
            if (!is_dir($cache)) {
                mkdir($cache, 0777, true);
            }
            $this->output->writeln(sprintf('  <gray>Downloading %s from the Poggit archive…</>', Output::escape($plugin)));
            try {
                (new Downloader())->download("https://poggit.pmmp.io/get/$plugin", $file);
            } catch (\Throwable $e) {
                @unlink($file);
                throw new UserError("Could not download $plugin from Poggit: {$e->getMessage()}");
            }
            if (!str_contains((string) file_get_contents($file, false, null, 0, 65536), '__HALT_COMPILER')) {
                @unlink($file);
                throw new UserError("Poggit has no plugin named $plugin.");
            }
        }

        return $file;
    }
}
