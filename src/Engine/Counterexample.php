<?php

declare(strict_types=1);

namespace Pront\Engine;

use Pront\ChoiceBuffer\Draw;
use Pront\Shrinker\Outcome;
use Pront\Shrinker\ShrinkResult;

/**
 * The minimal failing input found for a target (GLOSSARY): the shrunk consumed buffer, the failure
 * it reproduces, its replay token and how its flaky re-runs went (ADR-0011 section 1).
 */
final readonly class Counterexample
{
    /**
     * @param int $k the fresh trial that first failed
     * @param list<Draw> $buffer the minimal consumed buffer (the failing trial's when shrinking accepted nothing)
     * @param Outcome $failure the failure of trial `$k`, which every accepted shrink step reproduced
     * @param int $reruns flaky re-runs of `$buffer`
     * @param int $reproduced re-runs that reproduced `$failure`
     */
    public function __construct(
        public int $k,
        public array $buffer,
        public Outcome $failure,
        public ReplayToken $token,
        public ShrinkResult $shrink,
        public int $reruns,
        public int $reproduced,
    ) {
    }

    /**
     * Whether some re-run did not reproduce the failure: "not reproducible (flaky)".
     */
    public function flaky(): bool
    {
        return $this->reproduced < $this->reruns;
    }
}
