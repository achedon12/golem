<?php

declare(strict_types=1);

namespace Golem\Cli\Mutation;

/**
 * One small change to a file of the plugin.
 */
final class Mutant
{
    public function __construct(
        /** path relative to src/ */
        public readonly string $file,
        public readonly int $line,
        public readonly string $description,
        public readonly string $before,
        public readonly string $after,
        public readonly string $code,
    ) {
    }
}
