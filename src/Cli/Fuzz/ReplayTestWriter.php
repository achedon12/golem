<?php

declare(strict_types=1);

namespace Golem\Cli\Fuzz;

/**
 * Writes a Golem test that replays the fuzzing actions that led to a crash.
 */
final class ReplayTestWriter
{
    /** waits longer than this are shortened: the crash rarely depends on them */
    private const MAX_WAIT_TICKS = 100;

    public function __construct(
        private readonly string $testsDirectory,
        private readonly int $golems,
        private readonly int $seed,
    ) {
    }

    /**
     * @param array<string, mixed> $failure the fuzz_failure event
     * @param list<array{code: string, tick: int}> $actions from the start of the run to the one that threw
     * @return string the path of the test written
     */
    public function write(array $failure, array $actions, string $relativeFile): string
    {
        $exception = self::string($failure, 'exception');
        $short = substr($exception, (int) strrpos('\\' . $exception, '\\'));
        $line = (int) ($failure['line'] ?? 0);
        $where = preg_replace('/[^A-Za-z0-9]/', '', ucfirst(pathinfo($relativeFile, PATHINFO_FILENAME))) . $line;

        $class = $this->freeClassName('Fuzz' . $where);
        $body = $this->body($actions, $short);
        $timeout = $this->ticks($actions) + 200;

        $namespace = $this->namespace();
        $uses = ['Generator', 'Golem\\Attribute\\Timeout', 'Golem\\TestCase'];
        if (str_contains($body, 'new Vector3(')) {
            $uses[] = 'pocketmine\\math\\Vector3';
        }

        $code = "<?php\n\ndeclare(strict_types=1);\n\n"
            . ($namespace !== null ? "namespace $namespace;\n\n" : '')
            . implode('', array_map(static fn (string $use) => "use $use;\n", $uses))
            . "\n/**\n"
            . sprintf(" * Found by golem fuzz --seed=%d: %s: %s\n", $this->seed, $short, self::comment(self::string($failure, 'message')))
            . sprintf(" * at %s:%d, when %s %s.\n", $relativeFile, $line, self::string($failure, 'golem'), self::comment(self::string($failure, 'action')))
            . " *\n"
            . " * Replays the actions that led to it. Remove the ones that do not matter, then\n"
            . " * replace the crash with an assertion of what should happen instead.\n"
            . " */\n"
            . "final class $class extends TestCase\n{\n"
            . "    #[Timeout($timeout)]\n"
            . "    public function test{$short}At$where(): Generator\n    {\n"
            . $body
            . "    }\n}\n";

        $path = $this->testsDirectory . '/' . $class . '.php';
        file_put_contents($path, $code);

        return $path;
    }

    /**
     * @param list<array{code: string, tick: int}> $actions
     */
    private function body(array $actions, string $exception): string
    {
        $names = array_map(static fn (int $i) => "Fuzz$i", range(1, $this->golems));
        $variables = array_map(static fn (string $name) => '$' . lcfirst($name), $names);

        $lines = [
            sprintf('[%s] = yield $this->golems([%s]);', implode(', ', $variables), implode(', ', array_map(static fn (string $name) => "'$name'", $names))),
            '$fuzz1->op();',
            '',
        ];
        $previous = $actions[0]['tick'] ?? 0;
        foreach ($actions as $index => $action) {
            $wait = min(self::MAX_WAIT_TICKS, $action['tick'] - $previous);
            if ($wait > 0) {
                $lines[] = "yield \$this->wait($wait);";
            }
            $previous = $action['tick'];
            $code = $action['code'];
            if ($index === count($actions) - 1) {
                $code .= " // throws $exception";
            }
            array_push($lines, ...explode("\n", $code));
        }

        return implode('', array_map(static fn (string $line) => $line === '' ? "\n" : "        $line\n", $lines));
    }

    /**
     * @param list<array{code: string, tick: int}> $actions
     */
    private function ticks(array $actions): int
    {
        $ticks = 0;
        $previous = $actions[0]['tick'] ?? 0;
        foreach ($actions as $action) {
            $ticks += min(self::MAX_WAIT_TICKS, $action['tick'] - $previous);
            $previous = $action['tick'];
        }

        return $ticks;
    }

    private function freeClassName(string $base): string
    {
        $class = $base . 'Test';
        for ($i = 2; is_file($this->testsDirectory . '/' . $class . '.php'); $i++) {
            $class = $base . '_' . $i . 'Test';
        }

        return $class;
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

    private static function comment(string $text): string
    {
        return str_replace(['*/', "\n"], ['* /', ' '], $text);
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
