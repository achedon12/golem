<?php

declare(strict_types=1);

namespace Golem\Runtime;

use Golem\TestCase;

/**
 * One test method, as found by {@see TestDiscovery}.
 *
 * @internal
 */
final class TestDefinition
{
    /**
     * @param class-string<TestCase> $class
     */
    public function __construct(
        public readonly string $class,
        public readonly string $method,
        public readonly string $file,
        public readonly int $line,
        public readonly int $timeoutTicks,
        public readonly ?string $skipReason,
        public readonly bool $freshWorld = false,
    ) {
    }

    public function id(): string
    {
        return $this->class . '::' . $this->method;
    }
}
