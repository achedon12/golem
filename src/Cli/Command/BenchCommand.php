<?php

declare(strict_types=1);

namespace Golem\Cli\Command;

use Golem\Cli\Environment\Downloader;
use Golem\Cli\Environment\Toolchain;
use Golem\Cli\Options;
use Golem\Cli\Output;
use Golem\Cli\Project;
use Golem\Cli\Report\BenchComparison;
use Golem\Cli\Server\ServerProcess;
use Golem\Cli\Server\Workspace;
use Golem\Cli\UserError;

/**
 * golem bench: golems join a few at a time while Golem measures how the server holds up.
 */
final class BenchCommand
{
    public const VALUE_OPTIONS = ['players', 'duration', 'min-tps', 'baseline', 'save-baseline', 'log-events'];

    private const MAX_PLAYERS = 200;

    private const STEPS = 5;

    public function __construct(
        private readonly Output $output,
        private readonly string $golemSource,
    ) {
    }

    public function execute(Options $options): int
    {
        $project = Project::load($options->get('path', getcwd() ?: '.') ?? '.', $options->get('tests'), $options->get('pocketmine'));
        $players = (int) ($options->get('players') ?? 20);
        if ($players < 1 || $players > self::MAX_PLAYERS) {
            throw new UserError(sprintf('--players must be between 1 and %d.', self::MAX_PLAYERS));
        }
        $duration = max(10, (int) ($options->get('duration') ?? 60));
        $minTps = $options->get('min-tps') !== null ? (float) $options->get('min-tps') : null;
        $baselineFile = $options->get('baseline');
        $baseline = $baselineFile !== null ? BenchComparison::load($baselineFile) : null;

        $count = min(self::STEPS, $players);
        $steps = array_values(array_unique(array_map(static fn (int $i) => (int) round($players * $i / $count), range(1, $count))));
        $seconds = max(3, intdiv($duration, count($steps)));

        $cacheDirectory = Toolchain::defaultCacheDirectory();
        $toolchain = new Toolchain($cacheDirectory, new Downloader(), $this->output);
        $php = $options->get('php') ?? $toolchain->php();
        [$version, $phar] = $options->get('phar') !== null
            ? ['custom', (string) $options->get('phar')]
            : $toolchain->pocketmine($project->pocketmineVersion);

        $this->output->writeln();
        $this->output->writeln(sprintf(
            '  <bold>Golem</> <gray>is benchmarking %s on PocketMine-MP %s: up to %d players, %ds per step</>',
            Output::escape($project->name),
            Output::escape($version),
            $players,
            $seconds,
        ));
        $this->output->writeln();
        $this->output->writeln('  <gray>Players     TPS   Tick usage (avg / max)     Memory</>');

        $workspace = Workspace::create($project, $this->golemSource, null, $cacheDirectory, bench: ['steps' => $steps, 'seconds' => $seconds]);
        register_shutdown_function($workspace->delete(...));
        $process = new ServerProcess($php, $phar, $workspace, $options->has('verbose'));
        register_shutdown_function($process->stop(...));

        /** @var list<array{players: int, tps: float, usage: float, usageMax: float, memory: int}> $results */
        $results = [];
        /** @var list<array<string, mixed>> $listeners */
        $listeners = [];
        $abort = null;
        $root = $project->root;
        $eventLog = $options->get('log-events');
        $log = static function (array $event) use ($eventLog): void {
            if ($eventLog !== null) {
                file_put_contents($eventLog, json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            }
        };
        if ($eventLog !== null) {
            file_put_contents($eventLog, '');
        }

        try {
            $finished = $process->run(function (array $event) use (&$results, &$listeners, &$abort, $root, $log): void {
                switch ($event['type'] ?? null) {
                    case 'bench_step':
                        $players = (int) ($event['players'] ?? 0);
                        $tps = (float) ($event['tps'] ?? 0);
                        $results[] = [
                            'players' => $players,
                            'tps' => $tps,
                            'usage' => (float) ($event['usage'] ?? 0),
                            'usageMax' => (float) ($event['usageMax'] ?? 0),
                            'memory' => (int) ($event['memory'] ?? 0),
                        ];
                        $log(['type' => 'step'] + $results[array_key_last($results)]);
                        $this->output->writeln(sprintf(
                            '  %7d   %s   %8s / %-8s   %8s',
                            $players,
                            self::colorTps($tps),
                            sprintf('%.1f%%', (float) ($event['usage'] ?? 0)),
                            sprintf('%.1f%%', (float) ($event['usageMax'] ?? 0)),
                            self::megabytes((int) ($event['memory'] ?? 0)),
                        ));
                        break;
                    case 'bench_listeners':
                        $listeners = [];
                        foreach ((array) ($event['listeners'] ?? []) as $listener) {
                            if (is_array($listener)) {
                                // paths in task names differ between checkouts: keep them relative
                                $name = is_string($listener['name'] ?? null) ? $listener['name'] : '?';
                                $listeners[] = ['name' => str_replace($root . '/', '', $name)] + $listener;
                            }
                        }
                        break;
                    case 'abort':
                        $abort = is_string($event['message'] ?? null) ? $event['message'] : 'Aborted';
                        break;
                }
            }, count($steps) * ($seconds + 40) + 120);
            $crashLog = $finished ? '' : $process->logTail(6);
        } finally {
            $workspace->delete();
        }

        $this->output->writeln();
        if ($abort !== null) {
            throw new UserError($abort);
        }
        if (!$finished) {
            $this->output->writeln('  <fail> CRASHED </> The server crashed:');
            foreach (explode("\n", $crashLog) as $line) {
                $this->output->writeln('  <gray>│</> ' . Output::escape($line));
            }
            $this->output->writeln();

            return 1;
        }

        $this->slowest(array_slice($listeners, 0, 5), $project->name);
        $log(['type' => 'listeners', 'listeners' => array_slice($listeners, 0, 10)]);
        $status = $this->verdict($results, $minTps);

        $current = ['plugin' => $project->name, 'pocketmine' => $version, 'steps' => $results, 'listeners' => $listeners];
        if ($options->get('save-baseline') !== null) {
            BenchComparison::save($current, (string) $options->get('save-baseline'));
            $this->output->writeln(sprintf('  <gray>Saved the results to</> <cyan>%s</>', Output::escape((string) $options->get('save-baseline'))));
            $this->output->writeln();
        }
        if ($baseline !== null && $baselineFile !== null) {
            $comparison = new BenchComparison($baseline, $current);
            $comparison->render($this->output, $baselineFile);
            $summary = getenv('GITHUB_STEP_SUMMARY');
            if (is_string($summary) && $summary !== '') {
                file_put_contents($summary, $comparison->markdown($baselineFile), FILE_APPEND);
            }
            if ($comparison->regressions() > 0) {
                $status = 1;
            }
        }

        return $status;
    }

    /**
     * @param list<array<string, mixed>> $listeners
     */
    private function slowest(array $listeners, string $plugin): void
    {
        if ($listeners === []) {
            $this->output->writeln(sprintf('  <gray>No listener or task of %s ran during the benchmark.</>', Output::escape($plugin)));
            $this->output->writeln();

            return;
        }

        $this->output->writeln(sprintf('  <bold>Slowest listeners and tasks of %s</>', Output::escape($plugin)));
        foreach ($listeners as $listener) {
            $this->output->writeln(sprintf(
                '    %-58s <gray>%7d calls  %8.3f ms avg  %8.1f ms total</>',
                Output::escape(self::shorten(is_string($listener['name'] ?? null) ? $listener['name'] : '?')),
                (int) ($listener['count'] ?? 0),
                (float) ($listener['average'] ?? 0),
                (float) ($listener['total'] ?? 0),
            ));
        }
        $this->output->writeln();
    }

    /**
     * @param list<array{players: int, tps: float, usage: float, usageMax: float, memory: int}> $results
     */
    private function verdict(array $results, ?float $minTps): int
    {
        $threshold = $minTps ?? 19.5;
        $dropped = null;
        foreach ($results as $result) {
            if ($result['tps'] < $threshold) {
                $dropped = $result;
                break;
            }
        }
        $most = $results === [] ? 0 : max(array_column($results, 'players'));

        if ($dropped === null) {
            $this->output->writeln(sprintf('  <green>The server stayed above %s TPS up to %d players.</>', self::number($threshold), $most));
        } else {
            $this->output->writeln(sprintf(
                '  <%s>The server fell below %s TPS at %d players (%s TPS).</>',
                $minTps !== null ? 'red' : 'yellow',
                self::number($threshold),
                $dropped['players'],
                self::number($dropped['tps']),
            ));
        }
        $this->output->writeln('  <gray>Golems run in the server process: compare runs with each other rather than with real players.</>');
        $this->output->writeln();

        return $minTps !== null && $dropped !== null ? 1 : 0;
    }

    private static function colorTps(float $tps): string
    {
        $color = match (true) {
            $tps >= 19.5 => 'green',
            $tps >= 17.0 => 'yellow',
            default => 'red',
        };

        return sprintf('<%s>%5.1f</>', $color, $tps);
    }

    private static function megabytes(int $bytes): string
    {
        return sprintf('%d MB', (int) round($bytes / 1048576));
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.2f', $value), '0'), '.');
    }

    private static function shorten(string $text): string
    {
        return strlen($text) > 58 ? '…' . substr($text, -57) : $text;
    }
}
