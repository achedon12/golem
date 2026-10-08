<?php

declare(strict_types=1);

namespace Golem\Assert;

use pocketmine\math\Vector3;

/**
 * Turns a value into the stable, readable JSON a snapshot file holds.
 *
 * @internal
 */
final class Snapshot
{
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ) . "\n";
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            is_array($value) => array_map(self::normalize(...), $value),
            $value instanceof \JsonSerializable => self::normalize($value->jsonSerialize()),
            $value instanceof Vector3 => [$value->x, $value->y, $value->z],
            $value instanceof \UnitEnum => $value->name,
            default => throw new \LogicException(sprintf(
                'Cannot snapshot a %s: pass an array or a JsonSerializable value',
                get_debug_type($value),
            )),
        };
    }
}
