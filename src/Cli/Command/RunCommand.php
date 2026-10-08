<?php

declare(strict_types=1);

namespace Golem\Cli\Command;

use Golem\Cli\ChangeWatcher;
use Golem\Cli\Environment\CoverageDriver;
use Golem\Cli\Environment\Downloader;
use Golem\Cli\Environment\Toolchain;
use Golem\Cli\Options;
use Golem\Cli\Output;
use Golem\Cli\Project;
use Golem\Cli\Report\CloverReporter;
use Golem\Cli\Report\CompactReporter;
use Golem\Cli\Report\EventLogReporter;
use Golem\Cli\Report\HtmlReporter;
use Golem\Cli\Report\MarkdownReporter;
use Golem\Cli\Report\MultiReporter;
use Golem\Cli\Report\ConsoleReporter;
use Golem\Cli\Report\GitHubReporter;
use Golem\Cli\Report\JUnitReporter;
use Golem\Cli\Report\MigrationReport;
use Golem\Cli\Report\Reporter;
use Golem\Cli\Report\RunReport;
use Golem\Cli\Report\TeamCityReporter;
use Golem\Cli\Report\TestResult;
use Golem\Cli\Server\ServerProcess;
use Golem\Cli\Server\Workspace;
use Golem\Cli\UserError;

/**
 * golem run: boots a server with the plugin, runs the tests, reports.
 */
final class RunCommand
{
    public const VALUE_OPTIONS = ['path', 'tests', 'filter', 'pocketmine', 'php', 'phar', 'log-junit', 'timeout', 'compare', 'parallel', 'coverage-clover', 'log-events', 'repeat', 'report-html', 'report-markdown', 'with'];

    /** the seed of --random-order, chosen once so every server of a run shares it */
    private ?int $seed = null;

    public function __construct(
        private readonly Output $output,
        private readonly string $golemSource,
    ) {
    }

    /**
     * --repeat and --random-order, for the runtime.
     *
     * @return array{repeat: int, seed: ?int, perTestCoverage: bool, stopOnFailure: bool}
     */
    private function order(Options $options): array
    {
        $repeat = (int) ($options->get('repeat') ?? 1);
        if ($repeat < 1 || $repeat > 1000) {
            throw new UserError('--repeat must be a number from 1 to 1000.');
        }
        if ($options->has('random-order')) {
            $given = $options->get('random-order');
            $this->seed ??= $given !== null && ctype_digit($given) ? (int) $given : random_int(1, 999_999);
        }

        return [
            'repeat' => $repeat,
            'seed' => $this->seed,
            'perTestCoverage' => $options->has('per-test-coverage'),
            'stopOnFailure' => $options->has('stop-on-failure'),
        ];
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

    public function project(Options $options): Project
    {
        $project = Project::load(
            $options->get('path', getcwd() ?: '.') ?? '.',
            $options->get('tests'),
            $options->get('pocketmine'),
        );
        if (!is_dir($project->testsDirectory)) {
            throw new UserError("No tests folder at {$project->testsDirectory}. Create one with `golem init`.");
        }
        if ($options->get('with') !== null) {
            $plugins = [];
            foreach (array_filter(array_map('trim', explode(',', (string) $options->get('with')))) as $plugin) {
                $path = realpath($plugin);
                if ($path === false) {
                    throw new UserError("--with: there is no plugin at \"$plugin\".");
                }
                $plugins[] = $path;
            }
            $project = $project->withExtraPlugins($plugins);
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
        if ($options->get('coverage-clover') !== null && !class_exists(\XMLWriter::class)) {
            throw new UserError('--coverage-clover needs the xmlwriter extension in the PHP running Golem.');
        }

        $reporter = $options->has('teamcity') ? new TeamCityReporter() : new ConsoleReporter($this->output, $project->root);
        if ($options->get('log-events') !== null) {
            $reporter = new MultiReporter($reporter, new EventLogReporter((string) $options->get('log-events')));
        }
        $report = $this->runSuite($options, $project, $project->pocketmineVersion, $reporter);

        if ($junit !== null) {
            (new JUnitReporter())->write($report, $junit);
        }
        $clover = $options->get('coverage-clover');
        if ($clover !== null) {
            if ($report->coverage['lines'] ?? null) {
                (new CloverReporter())->write($report->coverage['lines'], $project->root . '/src', $clover);
            } else {
                $this->output->writeln('  <yellow>No line coverage was collected: no Clover report written.</>');
            }
        }
        if ($options->get('report-html') !== null) {
            (new HtmlReporter($project->root))->write($report, (string) $options->get('report-html'));
        }
        if ($options->get('report-markdown') !== null) {
            (new MarkdownReporter($project->root))->write($report, (string) $options->get('report-markdown'));
        }
        if (GitHubReporter::isAvailable()) {
            (new GitHubReporter($project->root))->write($report);
            $summary = getenv('GITHUB_STEP_SUMMARY');
            if (is_string($summary) && $summary !== '') {
                file_put_contents($summary, (new MarkdownReporter($project->root))->markdown($report) . "\n", FILE_APPEND);
            }
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

    /**
     * Runs the tests of the project once and returns what happened (also used by golem compat).
     */
    public function runSuite(Options $options, Project $project, string $pocketmine, Reporter $reporter): RunReport
    {
        $parallel = (int) ($options->get('parallel') ?? 1);
        if ($parallel > 1) {
            return $this->runParallel($options, $project, $pocketmine, $reporter, $parallel);
        }

        $startedAt = microtime(true);
        $cacheDirectory = Toolchain::defaultCacheDirectory();
        $toolchain = new Toolchain($cacheDirectory, new Downloader(), $this->output);
        $php = $options->get('php') ?? $toolchain->php();
        [$version, $phar] = $options->get('phar') !== null && $options->get('compare') === null
            ? ['custom', (string) $options->get('phar')]
            : $toolchain->pocketmine($pocketmine);
        $phpOptions = $this->coverageOptions($options, $php, $project);

        $this->output->writeln();
        $this->output->writeln(sprintf('  <bold>Golem</> <gray>is starting PocketMine-MP %s…</>', Output::escape($version)));

        $order = $this->order($options);
        $workspace = Workspace::create($project, $this->golemSource, $options->get('filter'), $cacheDirectory, $options->has('update-snapshots'), self::wantsCoverage($options), order: $order);
        if (!$options->has('keep')) {
            register_shutdown_function($workspace->delete(...)); // also runs when interrupted
        }
        $process = new ServerProcess($php, $phar, $workspace, $options->has('verbose'), $phpOptions);
        register_shutdown_function($process->stop(...)); // never leave a server behind
        $report = new RunReport();
        $report->label = $version;
        $report->repeat = $order['repeat'];
        $report->seed = $order['seed'];

        try {
            $finished = $process->run(function (array $event) use ($report, $reporter, $startedAt): void {
                switch ($event['type'] ?? null) {
                    case 'start':
                        $report->bootSeconds = microtime(true) - $startedAt;
                        $report->pocketmine = is_string($event['pocketmine'] ?? null) ? $event['pocketmine'] : null;
                        $report->plugin = is_string($event['plugin'] ?? null) ? $event['plugin'] : null;
                        $report->conflicts = self::conflicts($event['conflicts'] ?? null);
                        $reporter->started($report, (int) ($event['count'] ?? 0));
                        break;
                    case 'test':
                        $result = TestResult::fromEvent($event);
                        $report->results[] = $result;
                        $reporter->testFinished($result);
                        break;
                    case 'coverage':
                        $report->coverage = [
                            'commands' => self::counts($event['commands'] ?? []),
                            'listeners' => self::counts($event['listeners'] ?? []),
                            'lines' => self::lines($event['lines'] ?? null),
                        ];
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

    /**
     * Splits the test files between several servers, runs them side by side, and reports
     * the results as one run. Each class is reported at once, as it finishes.
     */
    private function runParallel(Options $options, Project $project, string $pocketmine, Reporter $reporter, int $workers): RunReport
    {
        $startedAt = microtime(true);
        $cacheDirectory = Toolchain::defaultCacheDirectory();
        $toolchain = new Toolchain($cacheDirectory, new Downloader(), $this->output);
        $php = $options->get('php') ?? $toolchain->php();
        [$version, $phar] = $options->get('phar') !== null && $options->get('compare') === null
            ? ['custom', (string) $options->get('phar')]
            : $toolchain->pocketmine($pocketmine);
        $phpOptions = $this->coverageOptions($options, $php, $project);

        $buckets = self::split(self::testFiles($project->testsDirectory), $workers);

        $this->output->writeln();
        $this->output->writeln(sprintf('  <bold>Golem</> <gray>is starting %d PocketMine-MP %s servers…</>', count($buckets), Output::escape($version)));

        $report = new RunReport();
        $report->label = $version;
        $order = $this->order($options);
        $report->repeat = $order['repeat'];
        $report->seed = $order['seed'];
        $counts = [];
        /** @var array<int, list<TestResult>> $current the results of the class each server is running */
        $current = [];
        /** @var list<TestResult> $waiting classes finished before every server started */
        $waiting = [];
        $emit = function (array $results) use ($report, $reporter, &$counts, &$waiting, $buckets): void {
            if (count($counts) < count($buckets)) {
                array_push($waiting, ...$results);

                return;
            }
            foreach ($results as $result) {
                $report->results[] = $result;
                $reporter->testFinished($result);
            }
        };
        $begin = function () use ($report, $reporter, &$counts, &$waiting, $emit): void {
            $reporter->started($report, array_sum($counts));
            $results = $waiting;
            $waiting = [];
            $emit($results);
        };

        $runs = [];
        $processes = [];
        $workspaces = [];
        foreach ($buckets as $index => $files) {
            $workspace = Workspace::create($project, $this->golemSource, $options->get('filter'), $cacheDirectory, $options->has('update-snapshots'), self::wantsCoverage($options), testFiles: $files, order: $order);
            $workspaces[] = $workspace;
            if (!$options->has('keep')) {
                register_shutdown_function($workspace->delete(...));
            }
            $process = new ServerProcess($php, $phar, $workspace, $options->has('verbose'), $phpOptions);
            register_shutdown_function($process->stop(...));
            $processes[] = $process;
            $current[$index] = [];

            $runs[] = [$process, function (array $event) use ($index, $report, $startedAt, &$counts, &$current, $emit, $begin, $buckets): void {
                switch ($event['type'] ?? null) {
                    case 'start':
                        $report->bootSeconds = max($report->bootSeconds, microtime(true) - $startedAt);
                        $report->pocketmine ??= is_string($event['pocketmine'] ?? null) ? $event['pocketmine'] : null;
                        $report->plugin ??= is_string($event['plugin'] ?? null) ? $event['plugin'] : null;
                        $report->conflicts = $report->conflicts ?: self::conflicts($event['conflicts'] ?? null);
                        $counts[$index] = (int) ($event['count'] ?? 0);
                        if (count($counts) === count($buckets)) {
                            $begin();
                        }
                        break;
                    case 'test':
                        $result = TestResult::fromEvent($event);
                        if ($current[$index] !== [] && $current[$index][0]->class !== $result->class) {
                            $emit($current[$index]);
                            $current[$index] = [];
                        }
                        $current[$index][] = $result;
                        break;
                    case 'coverage':
                        $report->coverage = self::mergeCoverage($report->coverage, [
                            'commands' => self::counts($event['commands'] ?? []),
                            'listeners' => self::counts($event['listeners'] ?? []),
                            'lines' => self::lines($event['lines'] ?? null),
                        ]);
                        break;
                    case 'abort':
                        $report->abortReason ??= is_string($event['message'] ?? null) ? $event['message'] : 'Aborted';
                        break;
                    case 'end':
                        $emit($current[$index]);
                        $current[$index] = [];
                        break;
                }
            }];
        }

        try {
            $finished = ServerProcess::runAll($runs, (int) ($options->get('timeout') ?? 600));
            // a server that crashed before starting or before its end event
            if (count($counts) < count($buckets)) {
                $counts += array_fill_keys(array_keys($buckets), 0);
                $begin();
            }
            foreach ($current as $results) {
                $emit($results);
            }

            $crashed = array_search(false, $finished, true);
            $report->crashed = $crashed !== false;
            $report->totalSeconds = microtime(true) - $startedAt;
            $reporter->finished($report, $report->isSuccessful() ? '' : $processes[$crashed === false ? 0 : $crashed]->logTail());
        } finally {
            foreach ($workspaces as $workspace) {
                if ($options->has('keep')) {
                    $this->output->writeln("  <gray>Server folder kept at {$workspace->path}</>");
                } else {
                    $workspace->delete();
                }
            }
        }

        return $report;
    }

    private static function wantsCoverage(Options $options): bool
    {
        return $options->has('coverage') || $options->get('coverage-clover') !== null || $options->has('per-test-coverage');
    }

    /**
     * @return list<string>
     */
    private function coverageOptions(Options $options, string $php, Project $project): array
    {
        if (!self::wantsCoverage($options)) {
            return [];
        }
        $phpOptions = CoverageDriver::phpOptions($php, $project->root . '/src');
        if ($phpOptions === null) {
            $this->output->writeln('  <yellow>Line coverage needs pcov or Xdebug in the server\'s PHP: only commands and listeners are covered.</>');

            return [];
        }

        return $phpOptions;
    }

    /**
     * @return list<string>
     */
    private static function testFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Shares the files between the servers, the biggest first, each to the least loaded
     * server: file size is a fair guess of how long its tests take.
     *
     * @param list<string> $files
     * @return list<list<string>> no empty bucket
     */
    private static function split(array $files, int $workers): array
    {
        $sizes = [];
        foreach ($files as $file) {
            $sizes[$file] = (int) filesize($file);
        }
        arsort($sizes);

        $buckets = array_fill(0, max(1, min($workers, count($files))), []);
        $loads = array_fill(0, count($buckets), 0);
        foreach ($sizes as $file => $size) {
            $lightest = (int) array_search(min($loads), $loads, true);
            $buckets[$lightest][] = (string) $file;
            $loads[$lightest] += $size;
        }

        return array_values(array_filter($buckets, static fn (array $bucket) => $bucket !== []));
    }

    /**
     * @param array{commands: array<string, int>, listeners: array<string, int>, lines: array<string, array<int, int>>|null}|null $total
     * @param array{commands: array<string, int>, listeners: array<string, int>, lines: array<string, array<int, int>>|null} $more
     * @return array{commands: array<string, int>, listeners: array<string, int>, lines: array<string, array<int, int>>|null}
     */
    private static function mergeCoverage(?array $total, array $more): array
    {
        if ($total === null) {
            return $more;
        }
        foreach (['commands', 'listeners'] as $kind) {
            foreach ($more[$kind] as $name => $count) {
                $total[$kind][$name] = ($total[$kind][$name] ?? 0) + $count;
            }
        }
        if ($more['lines'] !== null) {
            $lines = $total['lines'] ?? [];
            foreach ($more['lines'] as $file => $fileLines) {
                foreach ($fileLines as $line => $ran) {
                    $lines[$file][$line] = max($lines[$file][$line] ?? 0, $ran);
                }
                ksort($lines[$file]);
            }
            ksort($lines);
            $total['lines'] = $lines;
        }

        return $total;
    }

    /**
     * @return array<string, array<int, int>>|null
     */
    private static function lines(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $lines = [];
        foreach ($value as $file => $fileLines) {
            foreach (is_array($fileLines) ? $fileLines : [] as $line => $ran) {
                $lines[(string) $file][(int) $line] = $ran === 1 ? 1 : 0;
            }
        }

        return $lines;
    }

    /**
     * @return list<array{command: string, owner: string, takenBy: string}>
     */
    private static function conflicts(mixed $value): array
    {
        $conflicts = [];
        foreach (is_array($value) ? $value : [] as $conflict) {
            if (is_array($conflict) && is_string($conflict['command'] ?? null) && is_string($conflict['takenBy'] ?? null) && is_string($conflict['owner'] ?? null)) {
                $conflicts[] = ['command' => $conflict['command'], 'owner' => $conflict['owner'], 'takenBy' => $conflict['takenBy']];
            }
        }

        return $conflicts;
    }

    /**
     * @return array<string, int>
     */
    private static function counts(mixed $value): array
    {
        $counts = [];
        foreach (is_array($value) ? $value : [] as $name => $count) {
            $counts[(string) $name] = is_int($count) ? $count : 0;
        }

        return $counts;
    }
}
