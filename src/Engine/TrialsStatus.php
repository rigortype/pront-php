<?php

declare(strict_types=1);

namespace Pront\Engine;

/**
 * How the trial iterator of one target ended.
 */
enum TrialsStatus
{
    /** Every requested iteration passed. */
    case Completed;

    /** A fresh trial failed; the iterator stopped there. */
    case Failed;

    /** The discard threshold was exceeded: a generator-configuration error (ADR-0010 section 5). */
    case TooManyDiscards;
}
