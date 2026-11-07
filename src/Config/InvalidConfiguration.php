<?php

declare(strict_types=1);

namespace Pront\Config;

/**
 * `pront.php` could not be read as a configuration (ADR-0013: a configuration error, exit code 2).
 */
final class InvalidConfiguration extends \RuntimeException
{
}
