<?php

declare(strict_types=1);

namespace Golem\Cli\Report;

use Golem\Cli\Output;
use Golem\Cli\UserError;

/**
 * Compares a benchmark with a baseline saved earlier, to catch a change that slows the
 * server down.
 *
 * A step regresses when the TPS drops by a tick or more, or when the tick usage grows by
 * more than 30% and at least two points; a listener when its average time grows by half
 * and it took a millisecond or more in all. Smaller changes are noise.
 */
final class BenchComparison
{
    private const TPS_DROP = 1.0;
    private const USAGE_GROWTH = 1.3;
    private const USAGE_POINTS = 2.0;
    private const LISTENER_GROWTH = 1.5;
    private const LISTENER_MIN_TOTAL = 1.0;

    /** @var list<array{players: int, before: array<string, mixed>, after: array<string, mixed>, regressed: bool}> */
    private array $steps = [];

    /** @var list<array{name: string, before: float, after: float}> */
    private array $slowerListeners = [];

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $current
     */
    public function __construct(private readonly array $baseline, array $current)
    {
        $before = [];
        foreach (self::list($baseline['steps'] ?? null) as $step) {
            $before[(int) ($step['players'] ?? 0)] = $step;
        }
        foreach (self::list($current['steps'] ?? null) as $step) {
            $players = (int) ($step['players'] ?? 0);
            if (!isset($before[$players])) {
                continue;
            }
            $old = $before[$players];
            $tpsDrop = (float) ($old['tps'] ?? 0) - (float) ($step['tps'] ?? 0);
            $oldUsage = (float) ($old['usage'] ?? 0);
            $usage = (float) ($step['usage'] ?? 0);
            $this->steps[] = [
                'players' => $players,
                'before' => $old,
                'after' => $step,
                'regressed' => $tpsDrop >= self::TPS_DROP
                    || ($usage - $oldUsage >= self::USAGE_POINTS && $usage > $oldUsage * self::USAGE_GROWTH),
            ];
        }

        $oldListeners = [];
        foreach (self::list($baseline['listeners'] ?? null) as $listener) {
            $oldListeners[(string) ($listener['name'] ?? '')] = (float) ($listener['average'] ?? 0);
        }
        foreach (self::list($current['listeners'] ?? null) as $listener) {
            $name = (string) ($listener['name'] ?? '');
            $average = (float) ($listener['average'] ?? 0);
            $old = $oldListeners[$name] ?? null;
            if ($old !== null && $old > 0 && $average >= $old * self::LISTENER_GROWTH && (float) ($listener['total'] ?? 0) >= self::LISTENER_MIN_TOTAL) {
                $this->slowerListeners[] = ['name' => $name, 'before' => $old, 'after' => $average];
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function load(string $file): array
    {
        if (!is_file($file)) {
            throw new UserError("No benchmark baseline at $file. Save one with --save-baseline=$file.");
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !is_array($data['steps'] ?? null)) {
            throw new UserError("$file is not a benchmark baseline saved by golem bench.");
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $results
     */
    public static function save(array $results, string $file): void
    {
        $directory = dirname($file);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($file, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    public function regressions(): int
    {
        return count(array_filter($this->steps, static fn (array $step) => $step['regressed'])) + count($this->slowerListeners);
    }

    public function render(Output $output, string $file): void
    {
        $output->writeln(sprintf(
            '  <bold>Compared with %s</> <gray>(%s, PocketMine-MP %s)</>',
            Output::escape($file),
            Output::escape((string) ($this->baseline['plugin'] ?? '?')),
            Output::escape((string) ($this->baseline['pocketmine'] ?? '?')),
        ));
        if ($this->steps === []) {
            $output->writeln('  <yellow>No step with the same number of players: run with the same --players as the baseline.</>');
            $output->writeln();

            return;
        }
        $output->writeln('  <gray>Players     TPS            Tick usage avg       Memory</>');
        foreach ($this->steps as $step) {
            $before = $step['before'];
            $after = $step['after'];
            $color = $step['regressed'] ? 'red' : 'gray';
            $output->writeln(sprintf(
                '  %7d   %5.1f <%s>%-8s</>  %5.1f%% <%s>%-10s</>  %6d MB <gray>%s</>',
                $step['players'],
                (float) ($after['tps'] ?? 0),
                $color,
                self::delta((float) ($after['tps'] ?? 0) - (float) ($before['tps'] ?? 0), ''),
                (float) ($after['usage'] ?? 0),
                $color,
                self::delta((float) ($after['usage'] ?? 0) - (float) ($before['usage'] ?? 0), ' pt'),
                (int) round((int) ($after['memory'] ?? 0) / 1048576),
                self::delta(round(((int) ($after['memory'] ?? 0) - (int) ($before['memory'] ?? 0)) / 1048576), ' MB', 0),
            ));
        }
        foreach ($this->slowerListeners as $listener) {
            $output->writeln(sprintf(
                '  <red>↑</> %s <gray>%.3f → %.3f ms avg</> <red>(×%.1f)</>',
                Output::escape($listener['name']),
                $listener['before'],
                $listener['after'],
                $listener['after'] / $listener['before'],
            ));
        }
        $output->writeln();
        $regressions = $this->regressions();
        $output->writeln($regressions === 0
            ? '  <green>No performance regression.</>'
            : sprintf('  <red>%d performance regression(s).</>', $regressions));
        $output->writeln();
    }

    public function markdown(string $file): string
    {
        $lines = [
            '### Golem benchmark',
            '',
            sprintf('Compared with `%s` (%s, PocketMine-MP %s).', $file, (string) ($this->baseline['plugin'] ?? '?'), (string) ($this->baseline['pocketmine'] ?? '?')),
            '',
            '| Players | TPS | Tick usage | Memory |',
            '| ---: | ---: | ---: | ---: |',
        ];
        foreach ($this->steps as $step) {
            $before = $step['before'];
            $after = $step['after'];
            $lines[] = sprintf(
                '| %d | %.1f %s | %.1f%% %s%s | %d MB |',
                $step['players'],
                (float) ($after['tps'] ?? 0),
                self::delta((float) ($after['tps'] ?? 0) - (float) ($before['tps'] ?? 0), ''),
                (float) ($after['usage'] ?? 0),
                self::delta((float) ($after['usage'] ?? 0) - (float) ($before['usage'] ?? 0), ' pt'),
                $step['regressed'] ? ' :warning:' : '',
                (int) round((int) ($after['memory'] ?? 0) / 1048576),
            );
        }
        if ($this->slowerListeners !== []) {
            $lines[] = '';
            $lines[] = '**Slower listeners and tasks**';
            $lines[] = '';
            foreach ($this->slowerListeners as $listener) {
                $lines[] = sprintf('- `%s`: %.3f → %.3f ms on average (×%.1f)', $listener['name'], $listener['before'], $listener['after'], $listener['after'] / $listener['before']);
            }
        }
        $lines[] = '';
        $regressions = $this->regressions();
        $lines[] = $regressions === 0 ? 'No performance regression.' : "**$regressions performance regression(s).**";

        return implode("\n", $lines) . "\n\n";
    }

    private static function delta(float $value, string $unit, int $decimals = 1): string
    {
        if (round($value, $decimals) == 0) {
            return '(=)';
        }

        return sprintf('(%s%s%s)', $value > 0 ? '+' : '-', number_format(abs($value), $decimals), $unit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
