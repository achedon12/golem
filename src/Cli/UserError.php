<?php

declare(strict_types=1);

namespace Golem\Cli;

/**
 * A problem the user can fix (bad path, missing file...). Printed without a stack trace.
 */
final class UserError extends \RuntimeException
{
}
