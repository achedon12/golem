<?php

declare(strict_types=1);

namespace Golem\Assert;

/**
 * A failed expectation. Carries both sides so the report can show a diff.
 */
final class AssertionFailed extends \Exception
{
    public function __construct(
        string $message,
        public readonly ?string $expected = null,
        public readonly ?string $actual = null,
    ) {
        parent::__construct($message);
    }
}
