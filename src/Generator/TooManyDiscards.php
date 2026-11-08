<?php

declare(strict_types=1);

namespace Pront\Generator;

/**
 * The discard threshold was exceeded: a generator-configuration error (ADR-0010 section 5).
 */
final class TooManyDiscards extends \RuntimeException
{
}
