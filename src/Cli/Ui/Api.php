<?php

declare(strict_types=1);

namespace Golem\Cli\Ui;

use Golem\Cli\Application;

/**
 * The dashboard's API, behind PHP's built-in web server.
 *
 * Only this machine can reach it: the server listens on 127.0.0.1, requests must name it
 * as their host (no DNS rebinding), and API calls must carry the session's token in a
 * header, which other websites open in the browser cannot send.
 */
final class Api
{
    private const VERSION = '/^[A-Za-z0-9._\-]+(\/[A-Za-z0-9._\-]+(@[A-Za-z0-9._\-]+)?)?$/';

    public function __construct(
        private readonly string $token,
        private readonly int $port,
        private readonly string $projectRoot,
        private readonly string $testsDirectory,
        private readonly string $pocketmine,
        private readonly Runs $runs,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $env = static fn (string $name): string => (string) getenv($name);
        $package = $env('GOLEM_UI_PACKAGE');
        $tests = $env('GOLEM_UI_TESTS');

        return new self(
            $env('GOLEM_UI_TOKEN'),
            (int) $env('GOLEM_UI_PORT'),
            $env('GOLEM_UI_PROJECT'),
            $tests,
            $env('GOLEM_UI_POCKETMINE'),
            new Runs($env('GOLEM_UI_STATE') . '/runs', $env('GOLEM_UI_PHP'), $package . '/bin/golem', $env('GOLEM_UI_PROJECT')),
        );
    }

    /**
     * @return bool false to let the web server send a static file
     */
    public function handle(): bool
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if (!in_array($host, ["127.0.0.1:{$this->port}", "localhost:{$this->port}"], true)) {
            return $this->send(403, ['error' => 'This dashboard only answers on 127.0.0.1']);
        }

        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (!str_starts_with($path, '/api/')) {
            return false;
        }
        if (!hash_equals($this->token, (string) ($_SERVER['HTTP_X_GOLEM_TOKEN'] ?? ''))) {
            return $this->send(401, ['error' => 'Open the dashboard with the link golem ui printed']);
        }

        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $body = $method === 'POST' ? json_decode((string) file_get_contents('php://input'), true) : null;
        $body = is_array($body) ? $body : [];

        try {
            return match (true) {
                $method === 'GET' && $path === '/api/project' => $this->send(200, $this->project()),
                $method === 'GET' && $path === '/api/source' => $this->source((string) ($_GET['file'] ?? '')),
                $method === 'POST' && $path === '/api/runs' => $this->startRun($body),
                $method === 'GET' && preg_match('#^/api/runs/([\w-]+)$#', $path, $match) === 1 => $this->poll($match[1]),
                $method === 'POST' && preg_match('#^/api/runs/([\w-]+)/stop$#', $path, $match) === 1 => $this->send(200, ['stopped' => $this->runs->stop($match[1])]),
                default => $this->send(404, ['error' => "No $method $path"]),
            };
        } catch (InvalidRequest $e) {
            return $this->send(422, ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function project(): array
    {
        $manifest = [];
        foreach (file($this->projectRoot . '/plugin.yml', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+):\s*(.+?)\s*$/', $line, $match) === 1) {
                $manifest[$match[1]] = trim($match[2], '"\'');
            }
        }

        return [
            'name' => $manifest['name'] ?? basename($this->projectRoot),
            'version' => $manifest['version'] ?? null,
            'root' => $this->projectRoot,
            'pocketmine' => $this->pocketmine,
            'golem' => Application::VERSION,
            'tests' => TestCatalog::read($this->testsDirectory),
        ];
    }

    /**
     * @param array<mixed> $body
     */
    private function startRun(array $body): bool
    {
        $kind = (string) ($body['kind'] ?? '');
        $options = is_array($body['options'] ?? null) ? $body['options'] : [];
        $arguments = match ($kind) {
            'test' => ['run', '--log-events={run}/events.jsonl', ...$this->testArguments($options)],
            'fuzz' => ['fuzz', ...$this->fuzzArguments($options)],
            'bench' => ['bench', '--log-events={run}/events.jsonl', ...$this->benchArguments($options)],
            default => throw new InvalidRequest("Unknown run kind \"$kind\""),
        };
        $version = self::text($options, 'pocketmine', 100);
        if ($version !== null) {
            if (preg_match(self::VERSION, $version) !== 1) {
                throw new InvalidRequest('The PocketMine-MP version looks like 5.44.3 or owner/repository[@tag]');
            }
            $arguments[] = "--pocketmine=$version";
        }
        $arguments[] = '--path=' . $this->projectRoot;
        $arguments[] = '--ansi';

        $id = $this->runs->start($kind, $arguments, ['options' => $options]);

        return $this->send(200, ['id' => $id, 'command' => 'golem ' . implode(' ', array_filter($arguments, static fn (string $a) => !str_starts_with($a, '--log-events') && !str_starts_with($a, '--path') && $a !== '--ansi'))]);
    }

    /**
     * @param array<mixed> $options
     * @return list<string>
     */
    private function testArguments(array $options): array
    {
        $arguments = [];
        $filter = self::text($options, 'filter', 200);
        if ($filter !== null) {
            $arguments[] = "--filter=$filter";
        }
        $parallel = self::number($options, 'parallel', 1, 16);
        if ($parallel !== null && $parallel > 1) {
            $arguments[] = "--parallel=$parallel";
        }
        if (($options['coverage'] ?? false) === true) {
            $arguments[] = '--coverage';
        }
        if (($options['updateSnapshots'] ?? false) === true) {
            $arguments[] = '--update-snapshots';
        }

        return $arguments;
    }

    /**
     * @param array<mixed> $options
     * @return list<string>
     */
    private function fuzzArguments(array $options): array
    {
        $arguments = [];
        foreach (['duration' => [5, 3600], 'golems' => [1, 20], 'seed' => [0, PHP_INT_MAX]] as $name => [$min, $max]) {
            $value = self::number($options, $name, $min, $max);
            if ($value !== null) {
                $arguments[] = "--$name=$value";
            }
        }
        if (($options['writeTests'] ?? false) === true) {
            $arguments[] = '--write-tests';
        }

        return $arguments;
    }

    /**
     * @param array<mixed> $options
     * @return list<string>
     */
    private function benchArguments(array $options): array
    {
        $arguments = [];
        foreach (['players' => [1, 200], 'duration' => [10, 3600]] as $name => [$min, $max]) {
            $value = self::number($options, $name, $min, $max);
            if ($value !== null) {
                $arguments[] = "--$name=$value";
            }
        }
        $minTps = $options['minTps'] ?? null;
        if (is_int($minTps) || is_float($minTps) || (is_string($minTps) && is_numeric($minTps))) {
            $arguments[] = '--min-tps=' . max(0.0, min(20.0, (float) $minTps));
        }

        return $arguments;
    }

    private function poll(string $id): bool
    {
        $state = $this->runs->poll($id, (int) ($_GET['output'] ?? 0), (int) ($_GET['events'] ?? 0));

        return $state === null ? $this->send(404, ['error' => 'Unknown run']) : $this->send(200, $state);
    }

    /**
     * A file of the plugin, to show code around a failure or the lines coverage found.
     */
    private function source(string $file): bool
    {
        $path = realpath(str_starts_with($file, '/') ? $file : $this->projectRoot . '/' . $file);
        $root = rtrim((string) realpath($this->projectRoot), '/') . '/';
        if ($path === false || !str_starts_with($path, $root) || !in_array(pathinfo($path, PATHINFO_EXTENSION), ['php', 'yml', 'json'], true) || filesize($path) > 1_000_000) {
            return $this->send(404, ['error' => 'Not a source file of the plugin']);
        }

        return $this->send(200, ['file' => substr($path, strlen($root)), 'content' => (string) file_get_contents($path)]);
    }

    /**
     * @param array<mixed> $options
     */
    private static function text(array $options, string $name, int $length): ?string
    {
        $value = $options[$name] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        if (strlen($value) > $length || preg_match('/[\x00-\x1f]/', $value) === 1) {
            throw new InvalidRequest("\"$name\" is too long or has control characters");
        }

        return trim($value);
    }

    /**
     * @param array<mixed> $options
     */
    private static function number(array $options, string $name, int $min, int $max): ?int
    {
        $value = $options[$name] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (int) $value < $min || (int) $value > $max) {
            throw new InvalidRequest("\"$name\" must be a number from $min to $max");
        }

        return (int) $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function send(int $status, array $data): bool
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return true;
    }
}
