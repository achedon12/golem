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
        public readonly ?string $dataName = null,
        /** @var list<mixed> */
        public readonly array $arguments = [],
        public readonly ?string $worldTemplate = null,
    ) {
    }

    public function id(): string
    {
        return $this->class . '::' . $this->method . ($this->dataName !== null ? " ($this->dataName)" : '');
    }

    /**
     * @param list<mixed> $arguments
     */
    public function withData(string $name, array $arguments): self
    {
        return new self($this->class, $this->method, $this->file, $this->line, $this->timeoutTicks, $this->skipReason, $this->freshWorld, $name, $arguments, $this->worldTemplate);
    }
}
