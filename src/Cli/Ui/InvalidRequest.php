<?php

declare(strict_types=1);

namespace Golem\Cli\Ui;

/**
 * A dashboard request with a value Golem does not accept; its message is shown as is.
 */
final class InvalidRequest extends \RuntimeException
{
}
