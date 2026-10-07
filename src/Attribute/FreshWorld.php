<?php

declare(strict_types=1);

namespace Golem\Attribute;

/**
 * Runs a test (or every test of a class) in a brand new superflat world, deleted
 * afterwards. Same as calling {@see \Golem\TestCase::freshWorld()} at the start of setUp().
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class FreshWorld
{
}
