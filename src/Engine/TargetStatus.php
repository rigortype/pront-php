<?php

declare(strict_types=1);

namespace Pront\Engine;

/**
 * The verdict on one verified target.
 */
enum TargetStatus
{
    /** Every requested iteration passed. */
    case Passed;

    /** A counterexample was found, and every flaky re-run reproduced it. */
    case Failed;

    /** A counterexample was found, but not every flaky re-run reproduced it: "not reproducible (flaky)" (ADR-0011 section 1). */
    case Flaky;

    /** The discard threshold was exceeded: a generator-configuration error, never a silent stop (ADR-0010 section 5). */
    case TooManyDiscards;
}
