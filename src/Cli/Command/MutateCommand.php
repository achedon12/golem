<?php

declare(strict_types=1);

namespace Golem\Cli\Command;

use Golem\Cli\Mutation\Mutant;
use Golem\Cli\Mutation\Mutator;
use Golem\Cli\Options;
use Golem\Cli\Output;
use Golem\Cli\Project;
use Golem\Cli\UserError;

/**
 * golem mutate: changes the plugin's code one small mutation at a time and runs the tests
 * on each mutant. A mutant the tests do not catch shows a gap in them.
 *
 * The tests run once with coverage first, to know which tests run each line: a mutant only
 * runs those, and stops at the first failure. Mutants run on copies of the plugin, never on
 * its own files.
 */
final class MutateCommand
{
    public const VALUE_OPTIONS = ['workers', 'max', 'min-score'];

    private const KILLED = 'killed';
    private const SURVIVED = 'survived';
    private const TIMED_OUT = 'timed out';

    public function __construct(
        private readonly Output $output,
        private readonly string $packageRoot,
    ) {
    }

    public function execute(Options $options): int
    {
        $project = Project::load($options->get('path', getcwd() ?: '.') ?? '.', $options->get('tests'), $options->get('pocketmine'));
        $workers = min(16, max(1, (int) ($options->get('workers') ?? 2)));
        $max = max(1, (int) ($options->get('max') ?? 200));
        $minScore = $options->get('min-score') !== null ? (float) $options->get('min-score') : null;
        $eventLog = $options->get('log-events');
        if ($eventLog !== null) {
            file_put_contents($eventLog, '');
        }
        $log = static function (array $event) use ($eventLog): void {
            if ($eventLog !== null) {
                file_put_contents($eventLog, json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            }
        };

        $this->output->writeln();
        $this->output->writeln(sprintf('  <bold>Golem</> <gray>is mutating %s: running the tests once with coverage, to see which lines each test runs…</>', Output::escape($project->name)));

        [$coverage, $durations] = $this->baseline($options, $project);
        $mutants = [];
        $uncovered = 0;
        foreach (self::sourceFiles($project->root . '/src') as $file) {
            $relative = substr($file, strlen($project->root . '/src/'));
            $covered = array_fill_keys(array_keys($coverage[$relative] ?? []), true);
            $code = (string) file_get_contents($file);
            $all = Mutator::mutants($relative, $code, array_fill_keys(range(1, substr_count($code, "\n") + 1), true));
            foreach ($all as $mutant) {
                if (isset($covered[$mutant->line])) {
                    $mutants[] = $mutant;
                } else {
                    $uncovered++;
                }
            }
        }
        if ($mutants === []) {
            throw new UserError('No mutant to try: no line of src/ that a test runs has something to change.');
        }
        $skipped = max(0, count($mutants) - $max);
        $mutants = array_slice($mutants, 0, $max);

        $this->output->writeln(sprintf('  <gray>%d mutant(s) to try on %d server(s) side by side%s</>', count($mutants), $workers, $skipped > 0 ? ", $skipped more left out (--max)" : ''));
        $log(['type' => 'mutate_start', 'mutants' => count($mutants), 'uncovered' => $uncovered]);
        $this->output->write('  ');

        $results = $this->runMutants($options, $project, $mutants, $coverage, $durations, $workers, function (Mutant $mutant, string $status) use ($log): void {
            $this->output->write(match ($status) {
                self::KILLED => '<green>.</>',
                self::TIMED_OUT => '<yellow>T</>',
                default => '<red>M</>',
            });
            $log(['type' => 'mutant', 'status' => $status, 'file' => $mutant->file, 'line' => $mutant->line, 'description' => $mutant->description, 'before' => $mutant->before, 'after' => $mutant->after]);
        });
        $this->output->writeln();
        $this->output->writeln();

        $survivors = array_values(array_filter($results, static fn (array $r) => $r['status'] === self::SURVIVED));
        $killed = count($results) - count($survivors);
        $score = count($results) > 0 ? $killed / count($results) * 100 : 100.0;

        foreach ($survivors as ['mutant' => $mutant]) {
            $this->output->writeln(sprintf('  <red>✗ survived</> <cyan>src/%s:%d</> <gray>%s</>', Output::escape($mutant->file), $mutant->line, Output::escape($mutant->description)));
            $this->output->writeln('    <red>- ' . Output::escape($mutant->before) . '</>');
            $this->output->writeln('    <green>+ ' . Output::escape($mutant->after) . '</>');
        }
        if ($survivors !== []) {
            $this->output->writeln();
        }

        $color = $score >= 80 ? 'green' : ($score >= 50 ? 'yellow' : 'red');
        $this->output->writeln(sprintf('  <gray>Mutation score:</> <%s>%.1f%%</> <gray>(%d of %d mutants caught by the tests)</>', $color, $score, $killed, count($results)));
        if ($uncovered > 0) {
            $this->output->writeln(sprintf('  <gray>Not tried:</>      %d mutation(s) on lines no test runs (see --coverage)', $uncovered));
        }
        $this->output->writeln();
        $log(['type' => 'mutate_end', 'score' => round($score, 1), 'killed' => $killed, 'total' => count($results), 'uncovered' => $uncovered]);

        return $minScore !== null && $score < $minScore ? 1 : 0;
    }

    /**
     * Runs the tests once with per-test coverage.
     *
     * @return array{array<string, array<int, list<string>>>, array<string, float>} for each file, line => tests running it; and how long each test takes
     */
    private function baseline(Options $options, Project $project): array
    {
        $events = tempnam(sys_get_temp_dir(), 'golem-mutate-') ?: throw new UserError('Cannot create a temporary file');
        $arguments = ['run', '--path=' . $project->root, '--per-test-coverage', '--log-events=' . $events, '--no-ansi'];
        foreach (['filter', 'tests', 'pocketmine', 'php', 'phar'] as $name) {
            if ($options->get($name) !== null) {
                $arguments[] = "--$name=" . $options->get($name);
            }
        }
        $exit = $this->golem($arguments, $output);
        $coverage = [];
        $durations = [];
        $failed = [];
        foreach (file($events, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $event = json_decode($line, true);
            if (!is_array($event) || ($event['type'] ?? null) !== 'test') {
                continue;
            }
            $id = $event['class'] . '::' . $event['method'];
            if (in_array($event['status'] ?? null, ['failed', 'errored'], true)) {
                $failed[] = $id;
            }
            $durations[$id] = ($durations[$id] ?? 0) + (float) ($event['seconds'] ?? 0);
            foreach ((array) ($event['lines'] ?? []) as $file => $lines) {
                foreach ((array) $lines as $number) {
                    $coverage[(string) $file][(int) $number][$id] = $id;
                }
            }
        }
        @unlink($events);
        if ($failed !== [] || ($exit !== 0 && $durations === [])) {
            throw new UserError("The tests must pass before mutating the code, fix these first:\n" . ($failed !== [] ? '  ' . implode("\n  ", $failed) : $output));
        }
        if ($coverage === []) {
            throw new UserError('No line coverage was collected: golem mutate needs pcov or Xdebug in the server\'s PHP (PocketMine\'s PHP build ships Xdebug).');
        }

        return [array_map(static fn (array $lines) => array_map('array_values', $lines), $coverage), $durations];
    }

    /**
     * Runs the mutants on copies of the plugin, several at a time.
     *
     * @param list<Mutant> $mutants
     * @param array<string, array<int, list<string>>> $coverage
     * @param array<string, float> $durations
     * @param \Closure(Mutant, string): void $onResult
     * @return list<array{mutant: Mutant, status: string}>
     */
    private function runMutants(Options $options, Project $project, array $mutants, array $coverage, array $durations, int $workers, \Closure $onResult): array
    {
        $base = sys_get_temp_dir() . '/golem-mutate-' . bin2hex(random_bytes(4));
        $copies = [];
        for ($i = 0; $i < min($workers, count($mutants)); $i++) {
            $copies[$i] = "$base/$i";
            self::copy($project->root, $copies[$i]);
        }
        register_shutdown_function(static fn () => self::remove($base));

        $queue = $mutants;
        $running = [];
        $results = [];
        while ($queue !== [] || $running !== []) {
            foreach ($copies as $slot => $copy) {
                if (isset($running[$slot]) || $queue === []) {
                    continue;
                }
                $mutant = array_shift($queue);
                $tests = $coverage[$mutant->file][$mutant->line];
                $seconds = array_sum(array_map(static fn (string $id) => $durations[$id] ?? 5.0, $tests));
                $target = "$copy/src/{$mutant->file}";
                $original = (string) file_get_contents($target);
                file_put_contents($target, $mutant->code);

                $arguments = ['run', '--path=' . $copy, '--stop-on-failure', '--no-ansi', '--timeout=' . (int) (60 + 3 * $seconds), '--filter=' . implode('|', $tests)];
                foreach (['tests', 'pocketmine', 'php', 'phar'] as $name) {
                    if ($options->get($name) !== null) {
                        $arguments[] = "--$name=" . $options->get($name);
                    }
                }
                $process = proc_open(
                    [PHP_BINARY, $this->packageRoot . '/bin/golem', ...$arguments],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$copy.log", 'w'], 2 => ['file', "$copy.log", 'a']],
                    $pipes,
                );
                if (!is_resource($process)) {
                    file_put_contents($target, $original);
                    throw new UserError('Could not start golem for a mutant');
                }
                $running[$slot] = ['process' => $process, 'mutant' => $mutant, 'target' => $target, 'original' => $original];
            }

            foreach ($running as $slot => $run) {
                $status = proc_get_status($run['process']);
                if ($status['running']) {
                    continue;
                }
                proc_close($run['process']);
                file_put_contents($run['target'], $run['original']);
                $log = (string) @file_get_contents($copies[$slot] . '.log');
                $result = match (true) {
                    $status['exitcode'] === 0 => self::SURVIVED,
                    str_contains($log, 'did not finish within') => self::TIMED_OUT,
                    default => self::KILLED,
                };
                $results[] = ['mutant' => $run['mutant'], 'status' => $result];
                $onResult($run['mutant'], $result);
                unset($running[$slot]);
            }
            usleep(100_000);
        }

        return $results;
    }

    /**
     * @param list<string> $arguments
     * @param-out string $output the end of what golem printed
     */
    private function golem(array $arguments, mixed &$output): int
    {
        $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, $this->packageRoot . '/bin/golem', ...$arguments]));
        exec($command . ' 2>&1', $lines, $exit);
        $output = implode("\n", array_slice($lines, -30));

        return $exit;
    }

    /**
     * @return list<string>
     */
    private static function sourceFiles(string $directory): array
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
     * A copy of the plugin to mutate: its files, with vendor/ linked rather than copied.
     */
    private static function copy(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $name) {
            if (in_array($name, ['.', '..', '.git', 'node_modules'], true) || str_starts_with($name, 'brag-output')) {
                continue;
            }
            if ($name === 'vendor') {
                symlink("$from/vendor", "$to/vendor");
                continue;
            }
            if (is_dir("$from/$name")) {
                self::copy("$from/$name", "$to/$name");
            } else {
                copy("$from/$name", "$to/$name");
            }
        }
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::remove("$path/$name");
            }
        }
        @rmdir($path);
    }
}
