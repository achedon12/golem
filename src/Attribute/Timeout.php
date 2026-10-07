<?php

declare(strict_types=1);

namespace Golem\Attribute;

/**
 * Overrides how many server ticks a test (or every test of a class) may run.
 * The default is 200 ticks, about 10 seconds.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class Timeout
{
    public function __construct(public readonly int $ticks)
    {
    }
}
