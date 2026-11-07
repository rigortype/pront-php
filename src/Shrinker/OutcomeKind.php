<?php

declare(strict_types=1);

namespace Pront\Shrinker;

/**
 * The kinds of `Outcome`, plus `Invalid` for a candidate whose buffer could not produce an input
 * (an `InvalidChoice` the repair could not fix). Only `Failed` can be accepted.
 */
enum OutcomeKind
{
    case Passed;
    case Discarded;
    case Invalid;
    case Failed;
}
