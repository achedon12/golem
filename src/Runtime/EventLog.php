<?php

declare(strict_types=1);

namespace Golem\Runtime;

/**
 * Streams run events to the CLI, one JSON object per line.
 *
 * @internal
 */
final class EventLog
{
    /** @var resource */
    private $handle;

    public function __construct(string $path)
    {
        $handle = fopen($path, 'ab');
        if ($handle === false) {
            throw new \RuntimeException("Cannot write test events to $path");
        }
        $this->handle = $handle;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function write(string $type, array $payload = []): void
    {
        fwrite($this->handle, json_encode(['type' => $type] + $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        fflush($this->handle);
    }
}
