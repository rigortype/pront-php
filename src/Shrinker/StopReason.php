<?php

declare(strict_types=1);

namespace Pront\Shrinker;

/**
 * Why shrinking stopped; always reported, so a budget stop is never mistaken for a minimum
 * (ADR-0008, scope principles).
 */
enum StopReason
{
    /** A full round of every pass accepted no candidate: the result is a local minimum. */
    case Fixpoint;

    /** The next candidate would exceed the evaluation budget (ADR-0007 section 2). */
    case Budget;

    /** The wall-time safety net was reached between two evaluations (ADR-0007 section 2). */
    case Time;
}
