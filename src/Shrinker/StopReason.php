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

    /**
     * Replaying the failing buffer itself (the first evaluation) did not fail: nothing was shrunk,
     * and the result is the input buffer (a flaky or state-dependent failure).
     */
    case NotReproduced;

    /** The wall-time safety net was reached between two evaluations (ADR-0007 section 2). */
    case Time;
}
