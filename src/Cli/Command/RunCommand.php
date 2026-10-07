<?php

declare(strict_types=1);

namespace Golem\Cli\Command;

use Golem\Cli\Environment\Downloader;
use Golem\Cli\Environment\Toolchain;
use Golem\Cli\Options;
use Golem\Cli\Output;
use Golem\Cli\Project;
use Golem\Cli\Report\ConsoleReporter;
use Golem\Cli\Report\GitHubReporter;
use Golem\Cli\Report\JUnitReporter;
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
    public const VALUE_OPTIONS = ['path', 'tests', 'filter', 'pocketmine', 'php', 'phar', 'log-junit', 'timeout'];

    public function __construct(
        private readonly Output $output,
        private readonly string $golemSource,
    ) {
    }

    public function execute(Options $options): int
    {
        $startedAt = microtime(true);
        $project = Project::load(
            $options->get('path', getcwd() ?: '.') ?? '.',
            $options->get('tests'),
            $options->get('pocketmine'),
        );
        if (!is_dir($project->testsDirectory)) {
            throw new UserError("No tests folder at {$project->testsDirectory}. Create one with `golem init`.");
        }
        $junit = $options->get('log-junit');
        if ($junit !== null && !class_exists(\DOMDocument::class)) {
            throw new UserError('--log-junit needs the dom extension in the PHP running Golem.');
        }

        $toolchain = new Toolchain(Toolchain::defaultCacheDirectory(), new Downloader(), $this->output);
        $php = $options->get('php') ?? $toolchain->php();
        [$version, $phar] = $options->get('phar') !== null
            ? ['custom', (string) $options->get('phar')]
            : $toolchain->pocketmine($project->pocketmineVersion);

        $this->output->writeln();
        $this->output->writeln(sprintf('  <bold>Golem</> <gray>is starting PocketMine-MP %s…</>', Output::escape($version)));

        $workspace = Workspace::create($project, $this->golemSource, $options->get('filter'));
        $process = new ServerProcess($php, $phar, $workspace, $options->has('verbose'));
        $reporter = new ConsoleReporter($this->output, $project->root);
        $report = new RunReport();

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

            if ($junit !== null) {
                (new JUnitReporter())->write($report, $junit);
            }
            if (GitHubReporter::isAvailable()) {
                (new GitHubReporter($project->root))->write($report);
            }
        } finally {
            if ($options->has('keep')) {
                $this->output->writeln("  <gray>Server folder kept at {$workspace->path}</>");
            } else {
                $workspace->delete();
            }
        }

        return $report->isSuccessful() ? 0 : 1;
    }
}
