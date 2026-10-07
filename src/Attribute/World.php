<?php

declare(strict_types=1);

namespace Golem\Attribute;

/**
 * Runs a test (or every test of a class) in a copy of a world folder: your arena, your
 * lobby, your map. The template is never modified; the copy is deleted afterwards.
 * The path is relative to the plugin folder.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class World
{
    public function __construct(public readonly string $path)
    {
    }
}
