<?php

declare(strict_types=1);

namespace Pront\Cli;

/**
 * A command line `vendor/bin/pront` does not accept (ADR-0013: a usage error, exit code 2).
 */
final class UsageError extends \InvalidArgumentException
{
}
