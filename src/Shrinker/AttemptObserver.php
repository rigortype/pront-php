<?php

declare(strict_types=1);

namespace Pront\Shrinker;

use Pront\ChoiceBuffer\Draw;

/**
 * Sees every candidate evaluation as one attempt (ADR-0004 section 5), so the journal can write
 * `begin` before any user code runs for it and `end` once it has finished.
 */
interface AttemptObserver
{
    /**
     * Called before the candidate is replayed, so before generation or the property runs.
     *
     * @param list<Draw> $buffer the candidate recording; replaying it with
     *                         `ChoiceBuffer::repairing()` reproduces the attempt
     */
    public function begin(array $buffer): void;

    /**
     * Called after the replay returned, with its outcome; `null` when the replay threw an
     * exception that the shrinker does not classify (it is rethrown after this call).
     *
     * @param list<Draw> $buffer the same recording `begin()` received
     */
    public function end(array $buffer, ?Outcome $outcome): void;
}
