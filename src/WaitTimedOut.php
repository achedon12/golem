<?php

declare(strict_types=1);

namespace Golem;

/**
 * Thrown into a test when {@see TestCase::waitUntil()} gives up.
 */
final class WaitTimedOut extends \RuntimeException
{
}
