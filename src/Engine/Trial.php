<?php

declare(strict_types=1);

namespace Pront\Engine;

use Pront\ChoiceBuffer\Draw;

/**
 * One fresh trial yielded by the trial iterator (ADR-0004 item 1): fresh trial `$k` of a target,
 * its generated input and its consumed choice buffer.
 *
 * The trial iterator releases the input once the caller has sent the outcome, before it writes the
 * attempt's `end` record (the attempt includes the release of its input, ADR-0004 item 5). A caller
 * must therefore not keep its own reference to the input, or to values in it, past that point.
 */
final class Trial
{
    private ?Input $input;

    /**
     * @param list<Draw> $buffer
     */
    public function __construct(
        public readonly string $target,
        public readonly int $seed,
        public readonly int $k,
        Input $input,
        public readonly array $buffer,
    ) {
        $this->input = $input;
    }

    public function input(): Input
    {
        return $this->input ?? throw new \LogicException("Trial {$this->k} of {$this->target} has already been released");
    }

    /**
     * Drops the trial's reference to its input.
     *
     * @internal the trial iterator calls it
     */
    public function release(): void
    {
        $this->input = null;
    }
}
