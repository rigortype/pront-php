<?php

declare(strict_types=1);

namespace Pront\Generator;

/**
 * A drawn candidate that is not a valid input (ADR-0010 section 5): for example a `string` array key
 * PHP would turn into an int key (ADR-0007 section 1) or a constructor exception (ADR-0014 section
 * 3). The trial is a discard and counts toward the discard threshold (`Discards`).
 */
final class Rejected extends \RuntimeException
{
}
