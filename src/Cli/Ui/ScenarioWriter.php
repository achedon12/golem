<?php

declare(strict_types=1);

namespace Golem\Cli\Ui;

use Golem\Runtime\Fuzz\Literal;

/**
 * Turns a scenario built in the dashboard into a Golem test: golems, then steps, each one an
 * action of a golem or a check. Every value is checked and written as PHP code by Literal,
 * so nothing typed in the dashboard becomes code of its own.
 */
final class ScenarioWriter
{
    private const NAME = '/^[A-Za-z_][A-Za-z0-9_]{0,15}$/';
    private const ITEM = '/^[a-z0-9_:]{1,64}$/';
    private const GAMEMODES = ['SURVIVAL', 'CREATIVE', 'ADVENTURE', 'SPECTATOR'];

    /** the actions and checks a scenario can have */
    public const STEPS = [
        'chat', 'command', 'walk', 'wait', 'jump', 'clickButton', 'clickSlot', 'closeWindow', 'breakBlock', 'interactBlock', 'give', 'attack', 'quit',
        'receivedMessage', 'notReceivedMessage', 'lastMessage', 'formOpen', 'noFormOpen', 'windowOpen', 'noWindowOpen', 'hasItem', 'notHasItem',
        'health', 'gamemode', 'title', 'actionBar', 'scoreboardContains', 'online', 'offline', 'kicked', 'hasPermission', 'tpsAbove',
    ];

    public function __construct(private readonly string $testsDirectory)
    {
    }

    /**
     * @param array<mixed> $scenario
     */
    public function code(array $scenario): string
    {
        $class = $this->className($scenario);
        $method = $this->methodName($scenario);
        $golems = $this->golems($scenario);
        $uses = ['Generator', 'Golem\\TestCase'];
        $lines = [];

        $variables = array_map(static fn (array $golem) => self::variable($golem['name']), $golems);
        if (count($golems) === 1) {
            $lines[] = sprintf('%s = yield $this->golem(%s);', $variables[0], Literal::of($golems[0]['name']));
        } elseif ($golems !== []) {
            $lines[] = sprintf('[%s] = yield $this->golems([%s]);', implode(', ', $variables), implode(', ', array_map(static fn (array $g) => Literal::of($g['name']), $golems)));
        }
        foreach ($golems as $golem) {
            $variable = self::variable($golem['name']);
            if ($golem['op']) {
                $lines[] = $variable . '->op();';
            }
            if ($golem['gamemode'] !== null) {
                $lines[] = sprintf('%s->gamemode(GameMode::%s);', $variable, $golem['gamemode']);
                $uses[] = 'pocketmine\\player\\GameMode';
            }
        }
        $lines[] = '';

        $names = array_column($golems, 'name');
        foreach (self::list($scenario['steps'] ?? null) as $index => $step) {
            $line = $this->step($step, $names, $index + 1, $uses);
            array_push($lines, ...explode("\n", $line));
        }

        $namespace = $this->namespace();
        $description = self::text($scenario, 'description', 300, false);
        $uses = array_values(array_unique($uses));
        sort($uses);

        return "<?php\n\ndeclare(strict_types=1);\n\n"
            . ($namespace !== null ? "namespace $namespace;\n\n" : '')
            . implode('', array_map(static fn (string $use) => "use $use;\n", $uses))
            . "\n"
            . ($description !== '' ? "/**\n * " . str_replace(['*/', "\n"], ['* /', "\n * "], $description) . "\n */\n" : '')
            . "final class $class extends TestCase\n{\n"
            . "    public function $method(): Generator\n    {\n"
            . implode('', array_map(static fn (string $line) => $line === '' ? "\n" : "        $line\n", self::trimBlankLines($lines)))
            . "    }\n}\n";
    }

    /**
     * @param array<mixed> $scenario
     * @return array{file: string, class: string, filter: string}
     */
    public function write(array $scenario): array
    {
        $code = $this->code($scenario);
        $class = $this->className($scenario);
        $file = $this->testsDirectory . '/' . $class . '.php';
        if (is_file($file) && ($scenario['overwrite'] ?? false) !== true) {
            throw new InvalidRequest("tests/$class.php already exists: replace it, or give the scenario another name");
        }
        if (!is_dir($this->testsDirectory)) {
            mkdir($this->testsDirectory, 0777, true);
        }
        file_put_contents($file, $code);

        return ['file' => $file, 'class' => $class, 'filter' => "$class::"];
    }

    /**
     * @param array<mixed> $step
     * @param list<string> $names
     * @param list<string> $uses
     */
    private function step(array $step, array $names, int $number, array &$uses): string
    {
        $type = (string) ($step['type'] ?? '');
        if (!in_array($type, self::STEPS, true)) {
            throw new InvalidRequest("Step $number: unknown step \"$type\"");
        }
        $golem = function () use ($step, $names, $number): string {
            $name = (string) ($step['golem'] ?? '');
            if (!in_array($name, $names, true)) {
                throw new InvalidRequest("Step $number: pick one of the golems");
            }

            return self::variable($name);
        };
        $text = static fn (string $key = 'text', bool $required = true) => Literal::of(self::text($step, $key, 500, $required, "Step $number"));
        $int = static fn (string $key, int $min, int $max) => self::number($step, $key, $min, $max, "Step $number");
        $block = static function () use ($step, $number, $golem): string {
            $offset = array_map(static fn (string $axis) => self::number($step, $axis, -16, 16, "Step $number"), ['dx', 'dy', 'dz']);

            return sprintf('%s->position()->floor()->add(%d, %d, %d)', $golem(), ...$offset);
        };
        $item = static function () use ($step, $number): string {
            $name = strtolower(trim((string) ($step['item'] ?? '')));
            if (preg_match(self::ITEM, $name) !== 1) {
                throw new InvalidRequest("Step $number: an item name looks like diamond_sword");
            }
            $count = self::number($step, 'count', 1, 64, "Step $number");

            return $count === 1 ? sprintf('$this->item(%s)', Literal::of($name)) : sprintf('$this->item(%s, %d)', Literal::of($name), $count);
        };

        return match ($type) {
            'chat' => sprintf('%s->chat(%s);', $golem(), $text()),
            'command' => sprintf('%s->command(%s);', $golem(), Literal::of(ltrim(self::text($step, 'text', 500, true, "Step $number"), '/'))),
            'walk' => sprintf('yield %s->walk(%d, %d);', $golem(), $int('x', -100, 100), $int('z', -100, 100)),
            'wait' => sprintf('yield $this->wait(%d);', $int('ticks', 1, 6000)),
            'jump' => sprintf('%s->jump();', $golem()),
            'clickButton' => sprintf('%s->clickButton(%s);', $golem(), ctype_digit((string) ($step['text'] ?? '')) ? (string) (int) $step['text'] : $text()),
            'clickSlot' => sprintf('%s->clickSlot(%d);', $golem(), $int('slot', 0, 255)),
            'closeWindow' => sprintf('%s->closeWindow();', $golem()),
            'breakBlock' => sprintf('%s->breakBlock(%s);', $golem(), $block()),
            'interactBlock' => sprintf('%s->interactBlock(%s);', $golem(), $block()),
            'give' => sprintf('%s->give(%s);', $golem(), $item()),
            'attack' => sprintf('%s->attack(%s);', $golem(), $this->otherGolem($step, $names, $number)),
            'quit' => sprintf('%s->quit();', $golem()),
            'receivedMessage' => sprintf('$this->assertReceivedMessage(%s, %s);', $golem(), $text()),
            'notReceivedMessage' => sprintf('$this->assertNotReceivedMessage(%s, %s);', $golem(), $text()),
            'lastMessage' => sprintf('$this->assertSame(%s, %s->lastMessage());', $text(), $golem()),
            'formOpen' => sprintf('$this->assertFormOpen(%s%s);', $golem(), self::text($step, 'text', 500, false) !== '' ? ', ' . $text() : ''),
            'noFormOpen' => sprintf('$this->assertNoFormOpen(%s);', $golem()),
            'windowOpen' => sprintf('$this->assertWindowOpen(%s);', $golem()),
            'noWindowOpen' => sprintf('$this->assertNoWindowOpen(%s);', $golem()),
            'hasItem' => sprintf('$this->assertHasItem(%s, %s);', $golem(), $item()),
            'notHasItem' => sprintf('$this->assertNotHasItem(%s, %s);', $golem(), $item()),
            'health' => sprintf('$this->assertHealth(%s, %s);', $golem(), number_format($int('value', 0, 1000), 1, '.', '')),
            'gamemode' => $this->gamemodeCheck($step, $golem(), $number, $uses),
            'title' => sprintf('$this->assertTitle(%s, %s);', $golem(), $text()),
            'actionBar' => sprintf('$this->assertActionBar(%s, %s);', $golem(), $text()),
            'scoreboardContains' => sprintf('$this->assertScoreboardContains(%s, %s);', $golem(), $text()),
            'online' => sprintf('$this->assertOnline(%s);', $golem()),
            'offline' => sprintf('$this->assertOffline(%s);', $golem()),
            'kicked' => sprintf('$this->assertKicked(%s%s);', $golem(), self::text($step, 'text', 500, false) !== '' ? ', ' . $text() : ''),
            'hasPermission' => sprintf('$this->assertHasPermission(%s, %s);', $golem(), $text()),
            'tpsAbove' => sprintf('$this->assertTpsAbove(%s);', number_format($int('value', 1, 20), 1, '.', '')),
        };
    }

    /**
     * @param array<mixed> $step
     * @param list<string> $uses
     */
    private function gamemodeCheck(array $step, string $golem, int $number, array &$uses): string
    {
        $mode = strtoupper((string) ($step['text'] ?? ''));
        if (!in_array($mode, self::GAMEMODES, true)) {
            throw new InvalidRequest("Step $number: pick a game mode");
        }
        $uses[] = 'pocketmine\\player\\GameMode';

        return sprintf('$this->assertGamemode(%s, GameMode::%s);', $golem, $mode);
    }

    /**
     * @param array<mixed> $step
     * @param list<string> $names
     */
    private function otherGolem(array $step, array $names, int $number): string
    {
        $target = (string) ($step['target'] ?? '');
        if (!in_array($target, $names, true)) {
            throw new InvalidRequest("Step $number: pick the golem to attack");
        }

        return self::variable($target);
    }

    /**
     * @param array<mixed> $scenario
     * @return list<array{name: string, op: bool, gamemode: ?string}>
     */
    private function golems(array $scenario): array
    {
        $golems = [];
        $seen = [];
        foreach (self::list($scenario['golems'] ?? null) as $golem) {
            $name = trim((string) ($golem['name'] ?? ''));
            if (preg_match(self::NAME, $name) !== 1) {
                throw new InvalidRequest("\"$name\" is not a golem name: a letter first, then letters, digits and _, 16 at most");
            }
            if (isset($seen[strtolower($name)])) {
                throw new InvalidRequest("Two golems are named $name");
            }
            $seen[strtolower($name)] = true;
            $gamemode = strtoupper((string) ($golem['gamemode'] ?? ''));
            $golems[] = [
                'name' => $name,
                'op' => ($golem['op'] ?? false) === true,
                'gamemode' => in_array($gamemode, self::GAMEMODES, true) ? $gamemode : null,
            ];
        }

        return $golems;
    }

    /**
     * @param array<mixed> $scenario
     */
    private function className(array $scenario): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', self::text($scenario, 'name', 80, true, 'The scenario')) ?: [];
        $class = implode('', array_map(static fn (string $word) => ucfirst($word), $words));
        if ($class === '' || ctype_digit($class[0])) {
            throw new InvalidRequest('Name the scenario with letters first, like "Kit menu"');
        }

        return str_ends_with($class, 'Test') ? $class : $class . 'Test';
    }

    /**
     * @param array<mixed> $scenario
     */
    private function methodName(array $scenario): string
    {
        $test = self::text($scenario, 'test', 120, false);
        $words = preg_split('/[^A-Za-z0-9]+/', $test !== '' ? $test : 'scenario') ?: [];

        return 'test' . implode('', array_map(static fn (string $word) => ucfirst(strtolower($word)), array_filter($words, static fn (string $w) => $w !== '')));
    }

    /**
     * The namespace of the existing tests, so the new one sits with them.
     */
    private function namespace(): ?string
    {
        foreach (glob($this->testsDirectory . '/*.php') ?: [] as $file) {
            if (preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', (string) file_get_contents($file), $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    private static function variable(string $name): string
    {
        return '$' . lcfirst($name);
    }

    /**
     * @param array<mixed> $values
     */
    private static function text(array $values, string $key, int $length, bool $required, string $what = ''): string
    {
        $value = $values[$key] ?? '';
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($value === '' && $required) {
            throw new InvalidRequest(trim("$what: fill in \"$key\"", ': '));
        }
        if (mb_strlen($value) > $length) {
            throw new InvalidRequest("$what: \"$key\" is longer than $length characters");
        }

        return $value;
    }

    /**
     * @param array<mixed> $values
     */
    private static function number(array $values, string $key, int $min, int $max, string $what): int
    {
        $value = $values[$key] ?? null;
        if ($value === null || $value === '') {
            $value = $min > 0 ? $min : 0;
        }
        if (!is_numeric($value) || (int) $value < $min || (int) $value > $max) {
            throw new InvalidRequest("$what: \"$key\" must be a number from $min to $max");
        }

        return (int) $value;
    }

    /**
     * @return list<array<mixed>>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function trimBlankLines(array $lines): array
    {
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }
}
