<?php

declare(strict_types=1);

namespace Golem\Attribute;

/**
 * Skips a test, or every test of a class.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class Skip
{
    public function __construct(public readonly string $reason = '')
    {
    }
}
