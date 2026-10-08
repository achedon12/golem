<?php

declare(strict_types=1);

namespace Golem\Cli\Ui;

/**
 * Golem runs started from the dashboard: each one is a golem process in the background,
 * writing its console output, events and exit code to a folder of its own.
 */
final class Runs
{
    private const MAX_CHUNK = 262_144;

    public function __construct(
        private readonly string $directory,
        private readonly string $php,
        private readonly string $golem,
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @param list<string> $arguments golem's arguments, already checked
     * @param array<string, mixed> $meta kept with the run, for the dashboard
     */
    public function start(string $kind, array $arguments, array $meta): string
    {
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $folder = $this->folder($id);
        mkdir($folder, 0700, true);
        $arguments = array_map(static fn (string $argument) => str_replace('{run}', $folder, $argument), $arguments);
        file_put_contents("$folder/meta.json", json_encode(['id' => $id, 'kind' => $kind, 'startedAt' => time()] + $meta));

        $command = implode(' ', array_map('escapeshellarg', [$this->php, $this->golem, ...$arguments]));
        $inner = sprintf('%s > %s 2>&1; echo $? > %s', $command, escapeshellarg("$folder/output.log"), escapeshellarg("$folder/exit"));
        // its own process group, so stopping it also stops the server it started
        $setsid = trim((string) shell_exec('command -v setsid 2>/dev/null')) !== '' ? 'setsid ' : '';
        $pid = (int) shell_exec(sprintf('cd %s && %ssh -c %s > /dev/null 2>&1 & echo $!', escapeshellarg($this->projectRoot), $setsid, escapeshellarg($inner)));
        file_put_contents("$folder/pid", (string) $pid);

        return $id;
    }

    /**
     * What happened since the given offsets.
     *
     * @return array<string, mixed>|null null for an unknown run
     */
    public function poll(string $id, int $outputOffset, int $eventsOffset): ?array
    {
        $folder = $this->folder($id);
        if (!is_file("$folder/meta.json")) {
            return null;
        }
        $output = self::read("$folder/output.log", $outputOffset);
        $events = self::read("$folder/events.jsonl", $eventsOffset);
        // only whole lines: the last one may still be written
        $complete = strrpos($events, "\n");
        $events = $complete === false ? '' : substr($events, 0, $complete + 1);
        $exit = is_file("$folder/exit") ? trim((string) file_get_contents("$folder/exit")) : null;

        return [
            'running' => $exit === null,
            'exitCode' => $exit === null ? null : (int) $exit,
            'output' => mb_convert_encoding($output, 'UTF-8', 'UTF-8'),
            'outputOffset' => $outputOffset + strlen($output),
            'events' => array_values(array_filter(array_map(static fn (string $line) => json_decode($line, true), explode("\n", trim($events))), 'is_array')),
            'eventsOffset' => $eventsOffset + strlen($events),
        ];
    }

    public function stop(string $id): bool
    {
        $pid = (int) @file_get_contents($this->folder($id) . '/pid');
        if ($pid <= 1) {
            return false;
        }
        exec(sprintf('kill -TERM -- -%d 2>/dev/null || kill -TERM %d 2>/dev/null', $pid, $pid));

        return true;
    }

    private function folder(string $id): string
    {
        if (preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}$/', $id) !== 1) {
            return $this->directory . '/invalid';
        }

        return $this->directory . '/' . $id;
    }

    private static function read(string $file, int $offset): string
    {
        if (!is_file($file)) {
            return '';
        }
        $content = @file_get_contents($file, false, null, max(0, $offset), self::MAX_CHUNK);

        return $content === false ? '' : $content;
    }
}
