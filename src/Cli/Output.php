<?php

declare(strict_types=1);

namespace Golem\Cli;

/**
 * Terminal output with optional ANSI styling.
 *
 * Styles are written inline as <name>text</>, e.g. "<green>✓</> passed".
 */
final class Output
{
    private const STYLES = [
        'bold' => '1',
        'dim' => '2',
        'red' => '31',
        'green' => '32',
        'yellow' => '33',
        'blue' => '34',
        'magenta' => '35',
        'cyan' => '36',
        'gray' => '90',
        'pass' => '30;42;1',
        'fail' => '37;41;1',
        'skip' => '30;43;1',
    ];

    /**
     * @param resource $stream
     */
    public function __construct(
        private $stream,
        private readonly bool $decorated,
    ) {
    }

    public static function forStdout(?bool $colors = null): self
    {
        $colors ??= getenv('NO_COLOR') === false
            && (getenv('GITHUB_ACTIONS') === 'true' || (function_exists('stream_isatty') && stream_isatty(STDOUT)));

        return new self(STDOUT, $colors);
    }

    public function write(string $text): void
    {
        fwrite($this->stream, $this->format($text));
    }

    public function writeln(string $text = ''): void
    {
        $this->write($text . PHP_EOL);
    }

    public function isDecorated(): bool
    {
        return $this->decorated;
    }

    /**
     * Escapes text coming from tests so it is not read as styling.
     */
    public static function escape(string $text): string
    {
        return str_replace('<', '\\<', $text);
    }

    private function format(string $text): string
    {
        $stack = [];
        $result = preg_replace_callback(
            '/(?<!\\\\)<(\/?)([a-z]*)>/',
            function (array $match) use (&$stack): string {
                [, $closing, $name] = $match;
                if ($closing === '/') {
                    array_pop($stack);

                    return $this->decorated ? "\033[0m" . implode('', array_map(self::sequence(...), $stack)) : '';
                }
                if (!isset(self::STYLES[$name])) {
                    return $match[0];
                }
                $stack[] = $name;

                return $this->decorated ? self::sequence($name) : '';
            },
            $text,
        ) ?? $text;

        return str_replace('\\<', '<', $result);
    }

    private static function sequence(string $name): string
    {
        return "\033[" . self::STYLES[$name] . 'm';
    }
}
