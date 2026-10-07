<?php

declare(strict_types=1);

namespace Golem\Attribute;

/**
 * Marks a public method as a test when its name does not start with "test".
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Test
{
}
