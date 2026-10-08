<?php

declare(strict_types=1);

namespace Golem\Cli\Server;

use Golem\Cli\UserError;

/**
 * Runs PocketMine-MP and streams the events the Golem runtime writes.
 */
final class ServerProcess
{
    private const POLL_INTERVAL_US = 20_000;
    private const SHUTDOWN_GRACE_SECONDS = 30;

    /** @var resource|null */
    private $process = null;

    private int $eventsOffset = 0;

    private string $pendingLine = '';

    private bool $finished = false;

    private ?int $finishedAt = null;

    private int $deadline = 0;

    private int $timeoutSeconds = 0;

    private int $logOffset = 0;

    private int $polls = 0;

    public function __construct(
        private readonly string $php,
        private readonly string $phar,
        private readonly Workspace $workspace,
        private readonly bool $echoServerLog,
    ) {
    }

    /**
     * Starts the server, calls $onEvent for each event, and returns once it stopped.
     *
     * @param \Closure(array<string, mixed>): void $onEvent
     * @return bool whether the run reached its end event; false means the server crashed or hung
     */
    public function run(\Closure $onEvent, int $timeoutSeconds): bool
    {
        $this->begin($timeoutSeconds);
        while ($this->poll($onEvent)) {
            usleep(self::POLL_INTERVAL_US);
        }

        return $this->finished;
    }

    /**
     * Runs several servers side by side, each with its own event handler.
     *
     * @param list<array{self, \Closure(array<string, mixed>): void}> $runs
     * @return list<bool> for each server, whether its run reached its end event
     */
    public static function runAll(array $runs, int $timeoutSeconds): array
    {
        foreach ($runs as [$process]) {
            $process->begin($timeoutSeconds);
        }
        $running = $runs;
        while ($running !== []) {
            foreach ($running as $index => [$process, $onEvent]) {
                if (!$process->poll($onEvent)) {
                    unset($running[$index]);
                }
            }
            if ($running !== []) {
                usleep(self::POLL_INTERVAL_US);
            }
        }

        return array_map(static fn (array $run) => $run[0]->finished, $runs);
    }

    private function begin(int $timeoutSeconds): void
    {
        $this->start();
        $this->timeoutSeconds = $timeoutSeconds;
        $this->deadline = time() + $timeoutSeconds;
        $this->finished = false;
        $this->finishedAt = null;
        $this->logOffset = 0;
        $this->polls = 0;
    }

    /**
     * Handles what happened since the last call.
     *
     * @param \Closure(array<string, mixed>): void $onEvent
     * @return bool whether the server is still running
     */
    private function poll(\Closure $onEvent): bool
    {
        foreach ($this->readEvents() as $event) {
            $onEvent($event);
            if (in_array($event['type'] ?? null, ['end', 'abort'], true)) {
                $this->finished = true;
                $this->finishedAt = time();
            }
        }
        if ($this->echoServerLog) {
            $this->logOffset = $this->echoLog($this->logOffset);
        }

        if (!$this->isRunning()) {
            foreach ($this->readEvents() as $event) {
                $onEvent($event);
                $this->finished = $this->finished || in_array($event['type'] ?? null, ['end', 'abort'], true);
            }

            return $this->stopped();
        }

        // A crashed PocketMine waits two minutes before exiting, to throttle restarts.
        // Once the tests are over, or the log says it crashed, there is nothing to wait for.
        $crashed = !$this->finished && ++$this->polls % 25 === 0 && $this->logShowsCrash();
        $lingering = $this->finishedAt !== null && time() - $this->finishedAt > self::SHUTDOWN_GRACE_SECONDS;
        if ($crashed || $lingering || time() > $this->deadline) {
            $this->kill();
            if (time() > $this->deadline && !$this->finished) {
                throw new UserError("The test run did not finish within {$this->timeoutSeconds} seconds. Raise it with --timeout.");
            }

            return $this->stopped();
        }

        return true;
    }

    private function stopped(): bool
    {
        if ($this->echoServerLog) {
            $this->echoLog($this->logOffset);
        }

        return false;
    }

    /**
     * The part of the server log that explains a crash: from the first error on,
     * or the last lines when nothing was logged as an error.
     */
    public function logTail(int $lines = 40): string
    {
        $log = @file($this->workspace->logFile(), FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($log as $index => $line) {
            if (preg_match('#/(CRITICAL|EMERGENCY|ERROR)\]#', $line) === 1) {
                return implode("\n", array_slice($log, $index, $lines));
            }
        }

        return implode("\n", array_slice($log, -$lines));
    }

    private function start(): void
    {
        $command = [
            $this->php,
            $this->phar,
            '--no-wizard',
            '--disable-ansi',
            '--no-log-file',
            '--data=' . $this->workspace->path,
            '--plugins=' . $this->workspace->path . '/plugins',
            '--auto-report.enabled=0',
            '--player.save-player-data=0',
            '--auto-updater.enabled=0',
            '--anonymous-statistics.enabled=0',
        ];
        $env = getenv() + [];
        $env['GOLEM_RUNTIME_CONFIG'] = $this->workspace->runtimeConfig();

        $process = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $this->workspace->logFile(), 'a'],
                2 => ['file', $this->workspace->logFile(), 'a'],
            ],
            $pipes,
            $this->workspace->path,
            $env,
        );
        if (!is_resource($process)) {
            throw new UserError('Could not start PocketMine-MP');
        }
        $this->process = $process;
    }

    private function isRunning(): bool
    {
        return $this->process !== null && proc_get_status($this->process)['running'];
    }

    /**
     * Stops the server if it is still running. Safe to call more than once.
     */
    public function stop(): void
    {
        $this->kill();
    }

    private function kill(): void
    {
        if ($this->process === null) {
            return;
        }
        $pid = proc_get_status($this->process)['pid'];
        // PocketMine starts worker threads, not processes: killing the main pid is enough.
        if (function_exists('posix_kill')) {
            posix_kill($pid, 9);
        } else {
            proc_terminate($this->process, 9);
        }
        proc_close($this->process);
        $this->process = null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readEvents(): array
    {
        clearstatcache(true, $this->workspace->eventsFile());
        $content = @file_get_contents($this->workspace->eventsFile(), false, null, $this->eventsOffset);
        if ($content === false || $content === '') {
            return [];
        }
        $this->eventsOffset += strlen($content);

        $lines = explode("\n", $this->pendingLine . $content);
        $this->pendingLine = (string) array_pop($lines);

        $events = [];
        foreach ($lines as $line) {
            $event = json_decode($line, true);
            if (is_array($event)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private function logShowsCrash(): bool
    {
        $tail = $this->logTail(15);

        return str_contains($tail, 'server has crashed') || str_contains($tail, 'to throttle automatic restart');
    }

    private function echoLog(int $offset): int
    {
        clearstatcache(true, $this->workspace->logFile());
        $content = @file_get_contents($this->workspace->logFile(), false, null, $offset);
        if ($content === false || $content === '') {
            return $offset;
        }
        fwrite(STDERR, $content);

        return $offset + strlen($content);
    }
}
