<?php

declare(strict_types=1);

namespace Golem\Cli\Report;

/**
 * One test outcome, as reported by the runtime.
 */
final class TestResult
{
    public const PASSED = 'passed';
    public const FAILED = 'failed';
    public const ERRORED = 'errored';
    public const SKIPPED = 'skipped';

    /**
     * @param list<string> $trace
     */
    public function __construct(
        public readonly string $class,
        public readonly string $method,
        public readonly ?string $dataName,
        public readonly string $file,
        public readonly int $line,
        public readonly string $status,
        public readonly float $seconds,
        public readonly int $ticks,
        public readonly int $assertions,
        public readonly ?string $message,
        public readonly ?string $exception,
        public readonly ?string $expected,
        public readonly ?string $actual,
        public readonly ?string $failureFile,
        public readonly ?int $failureLine,
        public readonly array $trace,
    ) {
    }

    /**
     * @param array<string, mixed> $event
     */
    public static function fromEvent(array $event): self
    {
        $string = static fn (string $key): ?string => is_string($event[$key] ?? null) ? $event[$key] : null;
        $location = is_array($event['location'] ?? null) ? $event['location'] : [];

        return new self(
            (string) $string('class'),
            (string) $string('method'),
            $string('data'),
            (string) $string('file'),
            (int) ($event['line'] ?? 0),
            (string) $string('status'),
            (float) ($event['seconds'] ?? 0),
            (int) ($event['ticks'] ?? 0),
            (int) ($event['assertions'] ?? 0),
            $string('message'),
            $string('exception'),
            $string('expected'),
            $string('actual'),
            is_string($location['file'] ?? null) ? $location['file'] : null,
            isset($location['line']) ? (int) $location['line'] : null,
            array_values(array_filter((array) ($event['trace'] ?? []), 'is_string')),
        );
    }

    public function isProblem(): bool
    {
        return $this->status === self::FAILED || $this->status === self::ERRORED;
    }

    /**
     * "BlockProtectionTest"
     */
    public function shortClass(): string
    {
        $position = strrpos($this->class, '\\');

        return $position === false ? $this->class : substr($this->class, $position + 1);
    }

    /**
     * "testVisitorsCannotBreakBlocks" becomes "visitors cannot break blocks".
     */
    public function description(): string
    {
        $name = (string) preg_replace('/^test_?/', '', $this->method);
        $name = str_replace('_', ' ', $name);
        $name = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $name);

        $description = strtolower(trim($name));

        return $this->dataName !== null ? "$description ($this->dataName)" : $description;
    }

    /**
     * The test's name in reports, PHPUnit style: "testRank with data set \"admin\"".
     */
    public function name(): string
    {
        return $this->dataName !== null ? sprintf('%s with data set "%s"', $this->method, $this->dataName) : $this->method;
    }
}
