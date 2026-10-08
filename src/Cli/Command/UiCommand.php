<?php

declare(strict_types=1);

namespace Golem\Cli\Command;

use Golem\Cli\Environment\Toolchain;
use Golem\Cli\Options;
use Golem\Cli\Output;
use Golem\Cli\Project;
use Golem\Cli\UserError;

/**
 * golem ui: a dashboard on localhost to run the tests, fuzz, benchmark and build tests,
 * for the plugin in the current folder. Nothing leaves the machine.
 */
final class UiCommand
{
    public const VALUE_OPTIONS = ['port'];

    public function __construct(
        private readonly Output $output,
        private readonly string $packageRoot,
    ) {
    }

    public function execute(Options $options): int
    {
        $project = Project::load($options->get('path', getcwd() ?: '.') ?? '.', $options->get('tests'), $options->get('pocketmine'));
        $port = $options->get('port') !== null ? (int) $options->get('port') : self::freePort();
        $token = bin2hex(random_bytes(16));
        $state = Toolchain::defaultCacheDirectory() . '/ui/' . substr($token, 0, 12);
        if (!is_dir($state) && !mkdir($state, 0700, true) && !is_dir($state)) {
            throw new UserError("Cannot create $state");
        }

        $url = "http://127.0.0.1:$port/?token=$token";
        $this->output->writeln();
        $this->output->writeln(sprintf('  <bold>Golem</> <gray>dashboard for %s</>', Output::escape($project->name)));
        $this->output->writeln("  <cyan>$url</>");
        $this->output->writeln('  <gray>Local only: the dashboard runs on this machine. Ctrl+C to stop.</>');
        $this->output->writeln();

        $env = getenv() + [];
        $env['GOLEM_UI_TOKEN'] = $token;
        $env['GOLEM_UI_PORT'] = (string) $port;
        $env['GOLEM_UI_PACKAGE'] = $this->packageRoot;
        $env['GOLEM_UI_PROJECT'] = $project->root;
        $env['GOLEM_UI_TESTS'] = $project->testsDirectory;
        $env['GOLEM_UI_POCKETMINE'] = $project->pocketmineVersion;
        $env['GOLEM_UI_STATE'] = $state;
        $env['GOLEM_UI_PHP'] = PHP_BINARY;
        $env['PHP_CLI_SERVER_WORKERS'] = '4'; // polling while a run is being started

        $server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $this->packageRoot . '/resources/ui', $this->packageRoot . '/src/Cli/Ui/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $state . '/server.log', 'a'], 2 => ['file', $state . '/server.log', 'a']],
            $pipes,
            $project->root,
            $env,
        );
        if (!is_resource($server)) {
            throw new UserError('Could not start the dashboard server');
        }
        register_shutdown_function(static function () use ($server): void {
            proc_terminate($server);
        });
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, static function (): never {
                exit(0);
            });
        }

        usleep(300_000);
        if (!$options->has('no-open')) {
            self::open($url);
        }
        while (proc_get_status($server)['running']) {
            usleep(200_000);
        }
        $this->output->writeln('  <fail> STOPPED </> The dashboard server stopped: ' . Output::escape(trim((string) @file_get_contents($state . '/server.log'))));

        return 1;
    }

    private static function freePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        if ($socket === false) {
            return 8790;
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private static function open(string $url): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start ""',
            default => 'xdg-open',
        };
        @exec($command . ' ' . escapeshellarg($url) . ' > /dev/null 2>&1 &');
    }
}
