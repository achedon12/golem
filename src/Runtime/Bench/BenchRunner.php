<?php

declare(strict_types=1);

namespace Golem\Runtime\Bench;

use Golem\Golem;
use Golem\Runtime\EventLog;
use Golem\Runtime\Runtime;
use pocketmine\plugin\Plugin;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\timings\TimingsHandler;
use pocketmine\timings\TimingsRecord;

/**
 * Brings golems onto the server a batch at a time and measures how it holds up: after
 * each batch, TPS, tick usage and memory while the golems walk and chat; at the end, the
 * listeners and tasks of the plugin that took the most time.
 *
 * @internal
 */
final class BenchRunner
{
    /** ticks to let a batch settle before measuring: joins are the most expensive part */
    private const SETTLE_TICKS = 40;

    private const JOIN_TIMEOUT_TICKS = 600;

    /** @var list<Golem> */
    private array $golems = [];

    private int $step = 0;

    private int $joining = 0;

    private int $waited = 0;

    private int $measured = 0;

    private int $startTick = 0;

    private int $startTime = 0;

    /** @var list<float> tick usage of each measured tick, in percent */
    private array $usage = [];

    private ?string $phase = null;

    /** @var TaskHandler<ClosureTask>|null */
    private ?TaskHandler $task = null;

    /**
     * @param list<int> $steps how many golems are online at each step, increasing
     */
    public function __construct(
        private readonly Runtime $runtime,
        private readonly Plugin $subject,
        private readonly EventLog $events,
        private readonly array $steps,
        private readonly int $ticksPerStep,
        private readonly \Closure $onFinished,
    ) {
    }

    public function start(): void
    {
        TimingsHandler::setEnabled();

        $this->events->write('bench_start', ['steps' => $this->steps, 'seconds' => intdiv($this->ticksPerStep, 20)]);
        $this->task = $this->runtime->plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(fn () => $this->tick()), 1);
        $this->nextStep();
    }

    private function nextStep(): void
    {
        if ($this->step >= count($this->steps)) {
            $this->finish();

            return;
        }

        $target = $this->steps[$this->step];
        $this->phase = 'joining';
        $this->waited = 0;
        $this->joining = $target - count($this->golems);
        $scheduler = $this->runtime->plugin->getScheduler();
        for ($i = count($this->golems) + 1; $i <= $target; $i++) {
            $name = "Bench$i";
            // one join every other tick, as players would come, not all in the same tick
            $scheduler->scheduleDelayedTask(new ClosureTask(fn () => $this->runtime->golems->spawn($name)->then(function (Golem $golem): void {
                $this->golems[] = $golem;
                $this->joining--;
            }, function (\Throwable $e): void {
                $this->abort($e->getMessage());
            })), 1 + 2 * ($i - count($this->golems) - 1));
        }
    }

    private function tick(): void
    {
        foreach ($this->golems as $golem) {
            $this->animate($golem);
        }

        switch ($this->phase) {
            case 'joining':
                if ($this->joining <= 0) {
                    $this->phase = 'settling';
                    $this->waited = 0;
                } elseif (++$this->waited > self::JOIN_TIMEOUT_TICKS) {
                    $this->abort(sprintf('%d golem(s) did not finish joining within %d ticks', $this->joining, self::JOIN_TIMEOUT_TICKS));
                }
                break;
            case 'settling':
                if (++$this->waited >= self::SETTLE_TICKS) {
                    $this->phase = 'measuring';
                    $this->measured = 0;
                    $this->usage = [];
                    $this->startTick = $this->runtime->plugin->getServer()->getTick();
                    $this->startTime = hrtime(true);
                }
                break;
            case 'measuring':
                // the usage of the previous tick, the current one is not over
                $this->usage[] = $this->runtime->plugin->getServer()->getTickUsage();
                if (++$this->measured >= $this->ticksPerStep) {
                    $this->report();
                    $this->step++;
                    $this->nextStep();
                }
                break;
        }
    }

    /**
     * What players do: mostly walk around, sometimes talk.
     */
    private function animate(Golem $golem): void
    {
        if (!$golem->isOnline()) {
            return;
        }
        if (!$golem->isAlive()) {
            $golem->respawn();

            return;
        }
        $roll = mt_rand(0, 199);
        if ($roll < 5) {
            $golem->walk(mt_rand(-6, 6), mt_rand(-6, 6));
        } elseif ($roll === 5) {
            $golem->chat('hello');
        } elseif ($roll === 6) {
            $golem->jump();
        }
    }

    private function report(): void
    {
        $server = $this->runtime->plugin->getServer();
        $seconds = (hrtime(true) - $this->startTime) / 1e9;
        $ticks = $server->getTick() - $this->startTick;
        $average = $this->usage === [] ? 0.0 : array_sum($this->usage) / count($this->usage);

        $this->events->write('bench_step', [
            'players' => count($server->getOnlinePlayers()),
            'tps' => $seconds > 0 ? min(20.0, round($ticks / $seconds, 2)) : 20.0,
            'usage' => round($average, 1),
            'usageMax' => round($this->usage === [] ? 0.0 : max($this->usage), 1),
            'memory' => memory_get_usage(),
        ]);
    }

    private function finish(): void
    {
        $this->phase = null;
        $this->task?->cancel();
        $this->events->write('bench_listeners', ['listeners' => $this->slowest()]);
        TimingsHandler::setEnabled(false);
        $this->runtime->golems->despawnAll();
        ($this->onFinished)();
    }

    private function abort(string $message): void
    {
        if ($this->phase === null) {
            return; // already over
        }
        $this->phase = null;
        $this->task?->cancel();
        TimingsHandler::setEnabled(false);
        $this->runtime->golems->despawnAll();
        $this->events->write('abort', ['message' => $message]);
        $this->runtime->plugin->getServer()->shutdown();
    }

    /**
     * The plugin's listeners and tasks that took the most time, from PocketMine's timings,
     * slowest first.
     *
     * @return list<array{name: string, count: int, total: float, average: float, peak: float}> times in milliseconds
     */
    private function slowest(): array
    {
        $group = $this->subject->getDescription()->getFullName();
        $records = [];
        foreach (TimingsRecord::getAll() as $record) {
            if ($record->getGroup() !== $group || $record->getCount() === 0) {
                continue;
            }
            $records[] = [
                'name' => $record->getName(),
                'count' => $record->getCount(),
                'total' => round($record->getTotalTime() / 1e6, 3),
                'average' => round($record->getTotalTime() / $record->getCount() / 1e6, 4),
                'peak' => round($record->getPeakTime() / 1e6, 3),
            ];
        }
        usort($records, static fn (array $a, array $b) => $b['total'] <=> $a['total']);

        return array_slice($records, 0, 50);
    }
}
