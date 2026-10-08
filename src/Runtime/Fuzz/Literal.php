<?php

declare(strict_types=1);

namespace Golem\Runtime\Fuzz;

/**
 * Writes a value as PHP code, the way a person would: short arrays, double quotes only
 * when the string needs escapes.
 *
 * @internal
 */
final class Literal
{
    public static function of(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::float($value),
            is_string($value) => self::string($value),
            is_array($value) => self::array($value),
            default => throw new \InvalidArgumentException('Cannot write a ' . get_debug_type($value) . ' as PHP code'),
        };
    }

    private static function float(float $value): string
    {
        $code = var_export($value, true);

        return str_contains($code, '.') || str_contains($code, 'E') || str_contains($code, 'N') ? $code : $code . '.0';
    }

    private static function string(string $value): string
    {
        if (preg_match('/^[\x20-\x7e]*$/', $value) === 1) {
            return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
        }

        // anything else (control characters, unicode) as escapes, so the file stays readable
        $code = '';
        foreach (mb_str_split($value) as $character) {
            $code .= match (true) {
                $character === '\\' => '\\\\',
                $character === '"' => '\\"',
                $character === '$' => '\\$',
                $character === "\n" => '\\n',
                strlen($character) === 1 && ord($character) >= 0x20 && ord($character) < 0x7f => $character,
                default => sprintf('\\u{%X}', mb_ord($character)),
            };
        }

        return '"' . $code . '"';
    }

    /**
     * @param array<mixed> $value
     */
    private static function array(array $value): string
    {
        $items = [];
        foreach ($value as $key => $item) {
            $items[] = array_is_list($value) ? self::of($item) : self::of($key) . ' => ' . self::of($item);
        }

        return '[' . implode(', ', $items) . ']';
    }
}
