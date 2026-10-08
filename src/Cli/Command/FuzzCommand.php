<?php

declare(strict_types=1);

namespace Golem\Cli\Command;

use Golem\Cli\Environment\Downloader;
use Golem\Cli\Environment\Toolchain;
use Golem\Cli\Fuzz\ReplayTestWriter;
use Golem\Cli\Options;
use Golem\Cli\Output;
use Golem\Cli\Project;
use Golem\Cli\Server\ServerProcess;
use Golem\Cli\Server\Workspace;
use Golem\Cli\UserError;

/**
 * golem fuzz: golems do random things to the plugin; every exception is reported with
 * what led to it, and the seed to replay the run.
 */
final class FuzzCommand
{
    public const VALUE_OPTIONS = ['seed', 'duration', 'golems'];

    private const HISTORY = 6;

    public function __construct(
        private readonly Output $output,
        private readonly string $golemSource,
    ) {
    }

    public function execute(Options $options): int
    {
        $project = Project::load($options->get('path', getcwd() ?: '.') ?? '.', $options->get('tests'), $options->get('pocketmine'));
        $seed = $options->get('seed') !== null ? (int) $options->get('seed') : random_int(1, 999_999);
        $seconds = max(5, (int) ($options->get('duration') ?? 60));
        $golems = min(20, max(1, (int) ($options->get('golems') ?? 3)));

        $cacheDirectory = Toolchain::defaultCacheDirectory();
        $toolchain = new Toolchain($cacheDirectory, new Downloader(), $this->output);
        $php = $options->get('php') ?? $toolchain->php();
        [$version, $phar] = $options->get('phar') !== null
            ? ['custom', (string) $options->get('phar')]
            : $toolchain->pocketmine($project->pocketmineVersion);

        $this->output->writeln();
        $this->output->writeln(sprintf(
            '  <bold>Golem</> <gray>is fuzzing %s on PocketMine-MP %s: %d golems for %ds, seed %d</>',
            Output::escape($project->name),
            Output::escape($version),
            $golems,
            $seconds,
            $seed,
        ));

        $workspace = Workspace::create($project, $this->golemSource, null, $cacheDirectory, fuzz: ['seed' => $seed, 'seconds' => $seconds, 'golems' => $golems]);
        register_shutdown_function($workspace->delete(...));
        $process = new ServerProcess($php, $phar, $workspace, $options->has('verbose'));
        register_shutdown_function($process->stop(...));

        /** @var list<string> $history */
        $history = [];
        /** @var array<string, array{event: array<string, mixed>, count: int, before: list<string>, actions: list<array{code: string, tick: int}>}> $failures */
        $failures = [];
        /** @var list<array{code: string, tick: int}> $log every action so far, to replay a crash */
        $log = [];
        $actions = 0;
        $lastProgress = 0.0;
        $startedAt = microtime(true);

        try {
            $finished = $process->run(function (array $event) use (&$history, &$failures, &$log, &$actions, &$lastProgress, $startedAt): void {
                switch ($event['type'] ?? null) {
                    case 'fuzz_start':
                        $commands = is_array($event['commands'] ?? null) ? $event['commands'] : [];
                        $this->output->writeln(sprintf('  <dim>commands: %s</>', $commands === [] ? '(none)' : Output::escape('/' . implode(', /', $commands))));
                        break;
                    case 'fuzz_action':
                        $actions++;
                        $history[] = self::string($event, 'golem') . ' ' . self::string($event, 'action');
                        $history = array_slice($history, -self::HISTORY);
                        $log[] = ['code' => self::string($event, 'code'), 'tick' => (int) ($event['tick'] ?? 0)];
                        if (microtime(true) - $lastProgress > 5) {
                            $lastProgress = microtime(true);
                            $this->output->writeln(sprintf('  <gray>%3ds · %d actions · %d crash(es)</>', (int) (microtime(true) - $startedAt), $actions, count($failures)));
                        }
                        break;
                    case 'fuzz_failure':
                        $key = self::string($event, 'key');
                        if (isset($failures[$key])) {
                            $failures[$key]['count']++;
                        } else {
                            $failures[$key] = ['event' => $event, 'count' => 1, 'before' => array_slice($history, 0, -1), 'actions' => $log];
                        }
                        break;
                    case 'abort':
                        throw new UserError(self::string($event, 'message'));
                }
            }, $seconds + 120);
            $crashLog = $finished ? '' : $process->logTail(6);
        } finally {
            $workspace->delete();
        }

        $this->output->writeln();
        foreach ($failures as $failure) {
            $this->failure($failure['event'], $failure['count'], $failure['before'], $project->root);
        }
        if (!$finished) {
            $this->output->writeln('  <fail> CRASHED </> The server crashed:');
            foreach (explode("\n", $crashLog) as $line) {
                $this->output->writeln('  <gray>│</> ' . Output::escape($line));
            }
            $this->output->writeln('  <gray>The last actions were:</>');
            foreach ($history as $line) {
                $this->output->writeln('    <gray>·</> ' . Output::escape($line));
            }
            $this->output->writeln();
        }

        if ($options->has('write-tests') && $failures !== []) {
            $writer = new ReplayTestWriter($project->testsDirectory, $golems, $seed);
            if (!is_dir($project->testsDirectory)) {
                mkdir($project->testsDirectory, 0777, true);
            }
            foreach ($failures as $failure) {
                if ($failure['actions'] === []) {
                    continue; // failed while joining: nothing to replay
                }
                $path = $writer->write($failure['event'], $failure['actions'], $this->relative(self::string($failure['event'], 'file'), $project->root));
                $this->output->writeln(sprintf('  <gray>Wrote</> <cyan>%s</> <gray>to replay it</>', Output::escape($this->relative($path, $project->root))));
            }
            $this->output->writeln();
        }

        $clean = $failures === [] && $finished;
        $this->output->writeln(sprintf(
            '  <gray>Fuzzing:</> %d actions, %s',
            $actions,
            $clean ? '<green>nothing crashed</>' : sprintf('<red>%d problem(s)</>', count($failures) + ($finished ? 0 : 1)),
        ));
        if (!$clean) {
            $this->output->writeln(sprintf('  <gray>Replay:</>  vendor/bin/golem fuzz --seed=%d --duration=%d --golems=%d', $seed, $seconds, $golems));
            if (!$options->has('write-tests')) {
                $this->output->writeln('  <gray>Add --write-tests to turn each crash into a test.</>');
            }
        }
        $this->output->writeln();

        return $clean ? 0 : 1;
    }

    /**
     * @param array<string, mixed> $event
     * @param list<string> $before
     */
    private function failure(array $event, int $count, array $before, string $root): void
    {
        $relative = $this->relative(self::string($event, 'file'), $root);
        $exception = self::string($event, 'exception');
        $short = substr($exception, (int) strrpos('\\' . $exception, '\\'));

        $this->output->writeln(sprintf('  <fail> CRASH </> <bold>%s</>: %s%s', Output::escape($short), Output::escape(self::string($event, 'message')), $count > 1 ? " <gray>(×$count)</>" : ''));
        $this->output->writeln(sprintf('  <gray>at</> <cyan>%s:%d</>', Output::escape($relative), (int) ($event['line'] ?? 0)));
        $this->output->writeln(sprintf('  <gray>when</> %s %s', Output::escape(self::string($event, 'golem')), Output::escape(self::string($event, 'action'))));
        if ($before !== []) {
            $this->output->writeln('  <gray>just before:</>');
            foreach ($before as $line) {
                $this->output->writeln('    <gray>· ' . Output::escape($line) . '</>');
            }
        }
        $this->output->writeln();
    }

    private function relative(string $file, string $root): string
    {
        return str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function string(array $event, string $key): string
    {
        $value = $event[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
