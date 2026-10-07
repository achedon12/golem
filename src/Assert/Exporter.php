<?php

declare(strict_types=1);

namespace Golem\Assert;

use pocketmine\block\Block;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;

/**
 * Renders values for failure messages.
 */
final class Exporter
{
    private const MAX_DEPTH = 3;

    public static function export(mixed $value, int $depth = 0): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => '"' . addcslashes($value, "\"\\\n\r\t") . '"',
            is_int($value) => (string) $value,
            is_float($value) => self::exportFloat($value),
            is_array($value) => self::exportArray($value, $depth),
            $value instanceof Item => sprintf('Item(%s x%d)', $value->getName(), $value->getCount()),
            $value instanceof Block => sprintf('Block(%s)', $value->getName()),
            $value instanceof GameMode => sprintf('GameMode(%s)', $value->name),
            $value instanceof Vector3 => sprintf('(%s, %s, %s)', self::exportFloat($value->x), self::exportFloat($value->y), self::exportFloat($value->z)),
            $value instanceof \UnitEnum => $value::class . '::' . $value->name,
            $value instanceof \Stringable => $value::class . '(' . self::export((string) $value) . ')',
            is_object($value) => $value::class . '#' . spl_object_id($value),
            default => get_debug_type($value),
        };
    }

    private static function exportFloat(float|int $value): string
    {
        $text = (string) round((float) $value, 4);

        return str_contains($text, '.') || str_contains($text, 'E') ? $text : $text . '.0';
    }

    /**
     * @param array<mixed> $value
     */
    private static function exportArray(array $value, int $depth): string
    {
        if ($value === []) {
            return '[]';
        }
        if ($depth >= self::MAX_DEPTH) {
            return '[...]';
        }

        $isList = array_is_list($value);
        $indent = str_repeat('    ', $depth + 1);
        $lines = [];
        foreach ($value as $key => $item) {
            $prefix = $isList ? '' : self::export($key) . ' => ';
            $lines[] = $indent . $prefix . self::export($item, $depth + 1) . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $depth) . ']';
    }
}
