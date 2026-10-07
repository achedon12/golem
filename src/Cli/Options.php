<?php

declare(strict_types=1);

namespace Golem\Cli;

/**
 * Minimal argv parser: one optional command, then --flag, --key=value or --key value.
 */
final class Options
{
    /**
     * @param array<string, string|true> $options
     * @param list<string> $arguments
     */
    private function __construct(
        public readonly ?string $command,
        private readonly array $options,
        public readonly array $arguments,
    ) {
    }

    /**
     * @param list<string> $argv without the script name
     * @param list<string> $valueOptions options that take a value, so "--key value" works
     */
    public static function parse(array $argv, array $valueOptions): self
    {
        $command = null;
        $options = [];
        $arguments = [];

        for ($i = 0, $count = count($argv); $i < $count; $i++) {
            $arg = $argv[$i];
            if (str_starts_with($arg, '--')) {
                $name = substr($arg, 2);
                if (str_contains($name, '=')) {
                    [$name, $value] = explode('=', $name, 2);
                    $options[$name] = $value;
                } elseif (in_array($name, $valueOptions, true) && isset($argv[$i + 1])) {
                    $options[$name] = $argv[++$i];
                } else {
                    $options[$name] = true;
                }
            } elseif (str_starts_with($arg, '-') && strlen($arg) === 2) {
                $options[$arg[1]] = true;
            } elseif ($command === null && $arguments === []) {
                $command = $arg;
            } else {
                $arguments[] = $arg;
            }
        }

        return new self($command, $options, $arguments);
    }

    public function has(string $name): bool
    {
        return isset($this->options[$name]);
    }

    public function get(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map('strval', array_keys($this->options));
    }
}
