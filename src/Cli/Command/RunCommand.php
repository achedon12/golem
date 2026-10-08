<?php

declare(strict_types=1);

namespace Golem\Cli\Command;

use Golem\Cli\ChangeWatcher;
use Golem\Cli\Environment\Downloader;
use Golem\Cli\Environment\Toolchain;
use Golem\Cli\Options;
use Golem\Cli\Output;
use Golem\Cli\Project;
use Golem\Cli\Report\CompactReporter;
use Golem\Cli\Report\ConsoleReporter;
use Golem\Cli\Report\GitHubReporter;
use Golem\Cli\Report\JUnitReporter;
use Golem\Cli\Report\MigrationReport;
use Golem\Cli\Report\Reporter;
use Golem\Cli\Report\RunReport;
use Golem\Cli\Report\TestResult;
use Golem\Cli\Server\ServerProcess;
use Golem\Cli\Server\Workspace;
use Golem\Cli\UserError;

/**
 * golem run: boots a server with the plugin, runs the tests, reports.
 */
final class RunCommand
{
    public const VALUE_OPTIONS = ['path', 'tests', 'filter', 'pocketmine', 'php', 'phar', 'log-junit', 'timeout', 'compare'];

    public function __construct(
        private readonly Output $output,
        private readonly string $golemSource,
    ) {
    }

    public function execute(Options $options): int
    {
        if ($options->has('watch')) {
            $this->watch($options);
        }

        return $this->runOnce($options);
    }

    /**
     * Runs the tests, then again on every change, until interrupted.
     */
    private function watch(Options $options): never
    {
        // Ctrl+C: exit cleanly, so shutdown functions remove the current server folder
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, static function (): never {
                exit(130);
            });
        }

        $project = $this->project($options);
        $watcher = new ChangeWatcher([
            $project->root . '/src',
            $project->root . '/resources',
            $project->root . '/plugin.yml',
            $project->testsDirectory,
        ]);
        for (;;) {
            if ($this->output->isDecorated()) {
                $this->output->write("\033[H\033[2J"); // clear the screen
            }
            try {
                $this->runOnce($options);
            } catch (UserError $e) {
                $this->output->writeln();
                $this->output->writeln('  <fail> ERROR </> ' . Output::escape($e->getMessage()));
            }
            $this->output->writeln('  <gray>Watching for changes in src/ and tests/… (Ctrl+C to stop)</>');
            $watcher->waitForChange();
        }
    }

    private function project(Options $options): Project
    {
        $project = Project::load(
            $options->get('path', getcwd() ?: '.') ?? '.',
            $options->get('tests'),
            $options->get('pocketmine'),
        );
        if (!is_dir($project->testsDirectory)) {
            throw new UserError("No tests folder at {$project->testsDirectory}. Create one with `golem init`.");
        }

        return $project;
    }

    private function runOnce(Options $options): int
    {
        if ($options->get('compare') !== null) {
            return $this->compare($options, (string) $options->get('compare'));
        }

        $project = $this->project($options);
        $junit = $options->get('log-junit');
        if ($junit !== null && !class_exists(\DOMDocument::class)) {
            throw new UserError('--log-junit needs the dom extension in the PHP running Golem.');
        }

        $report = $this->runSuite($options, $project, $project->pocketmineVersion, new ConsoleReporter($this->output, $project->root));

        if ($junit !== null) {
            (new JUnitReporter())->write($report, $junit);
        }
        if (GitHubReporter::isAvailable()) {
            (new GitHubReporter($project->root))->write($report);
        }

        return $report->isSuccessful() ? 0 : 1;
    }

    /**
     * Runs the tests on two servers and reports what changes between them.
     */
    private function compare(Options $options, string $target): int
    {
        $project = $this->project($options);
        $reporter = new CompactReporter($this->output);
        $from = $this->runSuite($options, $project, $project->pocketmineVersion, $reporter);
        $to = $this->runSuite($options, $project, $target, $reporter);

        $migration = new MigrationReport($from, $to);
        $migration->render($this->output);

        $summary = getenv('GITHUB_STEP_SUMMARY');
        if (is_string($summary) && $summary !== '') {
            file_put_contents($summary, $migration->markdown(), FILE_APPEND);
        }

        $completed = !$from->crashed && $from->abortReason === null && !$to->crashed && $to->abortReason === null;

        return $completed && $migration->regressions() === 0 ? 0 : 1;
    }

    private function runSuite(Options $options, Project $project, string $pocketmine, Reporter $reporter): RunReport
    {
        $startedAt = microtime(true);
        $cacheDirectory = Toolchain::defaultCacheDirectory();
        $toolchain = new Toolchain($cacheDirectory, new Downloader(), $this->output);
        $php = $options->get('php') ?? $toolchain->php();
        [$version, $phar] = $options->get('phar') !== null && $options->get('compare') === null
            ? ['custom', (string) $options->get('phar')]
            : $toolchain->pocketmine($pocketmine);

        $this->output->writeln();
        $this->output->writeln(sprintf('  <bold>Golem</> <gray>is starting PocketMine-MP %s…</>', Output::escape($version)));

        $workspace = Workspace::create($project, $this->golemSource, $options->get('filter'), $cacheDirectory, $options->has('update-snapshots'));
        if (!$options->has('keep')) {
            register_shutdown_function($workspace->delete(...)); // also runs when interrupted
        }
        $process = new ServerProcess($php, $phar, $workspace, $options->has('verbose'));
        register_shutdown_function($process->stop(...)); // never leave a server behind
        $report = new RunReport();
        $report->label = $version;

        try {
            $finished = $process->run(function (array $event) use ($report, $reporter, $startedAt): void {
                switch ($event['type'] ?? null) {
                    case 'start':
                        $report->bootSeconds = microtime(true) - $startedAt;
                        $report->pocketmine = is_string($event['pocketmine'] ?? null) ? $event['pocketmine'] : null;
                        $report->plugin = is_string($event['plugin'] ?? null) ? $event['plugin'] : null;
                        $reporter->started($report, (int) ($event['count'] ?? 0));
                        break;
                    case 'test':
                        $result = TestResult::fromEvent($event);
                        $report->results[] = $result;
                        $reporter->testFinished($result);
                        break;
                    case 'abort':
                        $report->abortReason = is_string($event['message'] ?? null) ? $event['message'] : 'Aborted';
                        break;
                }
            }, (int) ($options->get('timeout') ?? 600));

            $report->crashed = !$finished;
            $report->totalSeconds = microtime(true) - $startedAt;
            $reporter->finished($report, $report->isSuccessful() ? '' : $process->logTail());
        } finally {
            if ($options->has('keep')) {
                $this->output->writeln("  <gray>Server folder kept at {$workspace->path}</>");
            } else {
                $workspace->delete();
            }
        }

        return $report;
    }
}
