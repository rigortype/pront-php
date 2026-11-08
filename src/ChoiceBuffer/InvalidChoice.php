<?php

declare(strict_types=1);

namespace Pront\ChoiceBuffer;

/**
 * A replayed slot that is not a valid position of its draw (outside the draw's range, or a float
 * pattern with no encoding). The attempt cannot produce a value; the shrinker discards or repairs
 * such a candidate (ADR-0002 section 4), it is never silently altered here.
 */
final class InvalidChoice extends \RuntimeException
{
}
