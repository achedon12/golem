<?php

declare(strict_types=1);

namespace Golem\Runtime\Fuzz;

use Golem\Golem;
use Golem\Runtime\EventLog;
use Golem\Runtime\Runtime;
use pocketmine\math\Facing;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginOwned;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\utils\TextFormat;

/**
 * Golems doing random things to a plugin, reporting every exception it throws.
 *
 * Everything random comes from one seeded generator, so a run can be replayed.
 *
 * @internal
 */
final class FuzzRunner
{
    /** arguments that tend to break commands */
    private const ARGUMENTS = ['', '0', '-1', '1', '2147483648', '-99999999999', '1.5', 'NaN', 'abc', 'true', 'null',
        '@a', '../../../etc', '§c', '🙂', "\u{202E}", '%s', '"', '\\', '[]', '{}'];

    private readonly \Random\Randomizer $random;

    /** @var list<Golem> */
    private array $golems = [];

    /** @var list<string> */
    private array $commands = [];

    /** @var array<string, true> failures already reported, by exception class and location */
    private array $seen = [];

    private int $actions = 0;

    private int $failures = 0;

    private int $elapsedTicks = 0;

    /** @var TaskHandler<ClosureTask>|null */
    private ?TaskHandler $task = null;

    public function __construct(
        private readonly Runtime $runtime,
        private readonly Plugin $subject,
        private readonly EventLog $events,
        private readonly int $seed,
        private readonly int $durationTicks,
        private readonly int $golemCount,
        private readonly \Closure $onFinished,
    ) {
        $this->random = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));
    }

    public function start(): void
    {
        $names = [];
        foreach ($this->runtime->plugin->getServer()->getCommandMap()->getCommands() as $command) {
            if ($command instanceof PluginOwned && $command->getOwningPlugin() === $this->subject) {
                $names[$command->getName()] = $command->getName();
            }
        }
        sort($names);
        $this->commands = $names;

        $this->events->write('fuzz_start', [
            'seed' => $this->seed,
            'golems' => $this->golemCount,
            'seconds' => intdiv($this->durationTicks, 20),
            'commands' => $this->commands,
        ]);

        for ($i = 1; $i <= $this->golemCount; $i++) {
            $this->join("Fuzz$i", $i === 1);
        }

        $this->task = $this->runtime->plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(fn () => $this->tick()), 1);
    }

    private function join(string $name, bool $operator): void
    {
        $this->runtime->golems->spawn($name)->then(function (Golem $golem) use ($operator): void {
            if ($operator) {
                $golem->op(); // reach the operator-only paths too
            }
            $this->golems[] = $golem;
        }, fn (\Throwable $e) => $this->failed($e, "$name joining", $name));
    }

    private function tick(): void
    {
        if (++$this->elapsedTicks >= $this->durationTicks) {
            $this->finish();

            return;
        }

        $this->golems = array_values(array_filter($this->golems, static fn (Golem $golem) => $golem->isOnline()));
        foreach ($this->golems as $golem) {
            if (!$golem->isAlive()) {
                $this->act($golem, 'respawned', static fn () => $golem->respawn());
            } elseif ($this->random->getInt(0, 3) === 0) {
                $this->randomAction($golem);
            }
        }
    }

    private function randomAction(Golem $golem): void
    {
        $roll = $this->random->getInt(1, 100);
        $other = $this->golems[$this->random->getInt(0, count($this->golems) - 1)];

        match (true) {
            $roll <= 40 && $this->commands !== [] => $this->runCommand($golem),
            $roll <= 55 && $golem->form() !== null => $this->answerForm($golem),
            $roll <= 60 && $golem->window() !== null => $this->clickWindow($golem),
            $roll <= 68 => $this->act($golem, $this->describeWalk($dx = $this->random->getInt(-6, 6), $dz = $this->random->getInt(-6, 6)), static fn () => $golem->walk($dx, $dz)),
            $roll <= 72 => $this->act($golem, 'jumped', static fn () => $golem->jump()),
            $roll <= 77 => $this->useBlock($golem, false),
            $roll <= 82 => $this->useBlock($golem, true),
            $roll <= 86 && $other !== $golem => $this->act($golem, "attacked {$other->name()}", static fn () => $golem->attack($other)),
            $roll <= 89 && $other !== $golem => $this->act($golem, "right-clicked {$other->name()}", static fn () => $golem->interactEntity($other)),
            $roll <= 92 => $this->act($golem, 'chatted', fn () => $golem->chat($this->pick(self::ARGUMENTS) . ' hello')),
            $roll <= 95 => $this->act($golem, 'toggled sneaking', static fn () => $golem->sneak(!$golem->player()->isSneaking())),
            $roll <= 97 => $this->act($golem, 'toggled sprinting', static fn () => $golem->sprint(!$golem->player()->isSprinting())),
            default => $this->reconnect($golem),
        };
    }

    private function runCommand(Golem $golem): void
    {
        if ($this->commands === []) {
            return;
        }
        $arguments = [];
        for ($i = $this->random->getInt(0, 3); $i > 0; $i--) {
            $arguments[] = $this->random->getInt(0, 4) === 0 ? $this->otherName() : $this->pick(self::ARGUMENTS);
        }
        $line = trim($this->pick($this->commands) . ' ' . implode(' ', array_map(self::quote(...), $arguments)));
        $this->act($golem, 'ran /' . self::shorten($line), static fn () => $golem->command($line));
    }

    private function answerForm(Golem $golem): void
    {
        $answers = [null, true, false, 0, 1, 2, 7, -1, 999, 1.5, 'abc', '', [], [null], [1, 'x', true], ['key' => 'value'], [str_repeat('a', 500)]];
        $answer = $answers[$this->random->getInt(0, count($answers) - 1)];
        $title = $golem->formData()['title'] ?? '?';
        $this->act(
            $golem,
            sprintf('answered %s to the form "%s"', self::shorten((string) json_encode($answer)), TextFormat::clean(is_string($title) ? $title : '?')),
            static fn () => $golem->submitForm($answer),
        );
    }

    private function clickWindow(Golem $golem): void
    {
        $window = $golem->window();
        if ($window === null) {
            return;
        }
        if ($this->random->getInt(0, 4) === 0) {
            $this->act($golem, 'closed the window', static fn () => $golem->closeWindow());

            return;
        }
        $slot = $this->random->getInt(0, max(0, $window->getSize() - 1));
        $this->act($golem, "clicked slot $slot of the window", static fn () => $golem->clickSlot($slot));
    }

    private function useBlock(Golem $golem, bool $break): void
    {
        $target = $golem->position()->floor()->add($this->random->getInt(-2, 2), $this->random->getInt(-2, 1), $this->random->getInt(-2, 2));
        $where = sprintf('(%d, %d, %d)', $target->x, $target->y, $target->z);
        if ($break) {
            $this->act($golem, "broke the block at $where", static fn () => $golem->breakBlock($target));
        } else {
            $face = $this->random->getInt(Facing::DOWN, Facing::EAST);
            $this->act($golem, "right-clicked the block at $where", static fn () => $golem->interactBlock($target, $face));
        }
    }

    private function reconnect(Golem $golem): void
    {
        $name = $golem->name();
        $this->act($golem, 'disconnected', static fn () => $golem->quit('Fuzzing'));
        $this->runtime->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(fn () => $this->join($name, $name === 'Fuzz1')), 10);
    }

    /**
     * Runs one action, recording it and any exception it lets through.
     */
    private function act(Golem $golem, string $description, \Closure $action): void
    {
        $this->actions++;
        $this->events->write('fuzz_action', ['golem' => $golem->name(), 'action' => $description]);
        try {
            $action();
        } catch (\Throwable $e) {
            $this->failed($e, $description, $golem->name());
        }
    }

    private function failed(\Throwable $e, string $action, string $golem): void
    {
        $location = $this->origin($e);
        $key = $e::class . '@' . $location['file'] . ':' . $location['line'];
        $first = !isset($this->seen[$key]);
        $this->seen[$key] = true;
        $this->failures++;

        $this->events->write('fuzz_failure', [
            'key' => $key,
            'first' => $first,
            'golem' => $golem,
            'action' => $action,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $location['file'],
            'line' => $location['line'],
        ]);
    }

    /**
     * Where the exception comes from: the first frame outside Golem and PocketMine.
     *
     * @return array{file: string, line: int}
     */
    private function origin(\Throwable $e): array
    {
        $golem = str_replace('\\', '/', dirname(__DIR__, 2));
        $frames = [['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()];
        foreach ($frames as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            if ($file !== '' && !str_starts_with($file, 'phar://') && !str_ends_with($file, '.phar') && !str_starts_with($file, $golem)) {
                return ['file' => $file, 'line' => (int) ($frame['line'] ?? 0)];
            }
        }

        return ['file' => $e->getFile(), 'line' => $e->getLine()];
    }

    private function finish(): void
    {
        $this->task?->cancel();
        $this->runtime->golems->despawnAll();
        $this->events->write('fuzz_end', ['actions' => $this->actions, 'failures' => $this->failures, 'unique' => count($this->seen)]);
        ($this->onFinished)();
    }

    /**
     * @template T
     * @param non-empty-list<T> $values
     * @return T
     */
    private function pick(array $values): mixed
    {
        return $values[$this->random->getInt(0, count($values) - 1)];
    }

    private function otherName(): string
    {
        return $this->golems === [] ? 'Fuzz1' : $this->golems[$this->random->getInt(0, count($this->golems) - 1)]->name();
    }

    private function describeWalk(int $dx, int $dz): string
    {
        return "walked ($dx, $dz)";
    }

    private static function shorten(string $text): string
    {
        return mb_strlen($text) > 60 ? mb_substr($text, 0, 57) . '…' : $text;
    }

    private static function quote(string $argument): string
    {
        return $argument === '' || str_contains($argument, ' ') ? '"' . $argument . '"' : $argument;
    }
}
