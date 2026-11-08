<?php

declare(strict_types=1);

namespace Pront\Generator;

/**
 * No generator can be synthesized for a type or a constructor (ADR-0010 section 1). The caller
 * reports it as a pre-run error; it is never skipped.
 */
final class UngeneratableType extends \InvalidArgumentException
{
}
