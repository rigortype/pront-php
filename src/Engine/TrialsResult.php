<?php

declare(strict_types=1);

namespace Pront\Engine;

use Pront\ChoiceBuffer\Draw;
use Pront\Shrinker\Outcome;

/**
 * The return value of the trial iterator (`Engine::trials()`).
 */
final readonly class TrialsResult
{
    /**
     * @param int $trials fresh trials run, discarded ones included (the last `k`)
     * @param int $passed trials that passed (they count toward the iterations)
     * @param int $discards trials discarded (they count toward `k`, not toward the iterations)
     * @param list<Draw>|null $failingBuffer the consumed buffer of the failing trial `$trials`
     */
    public function __construct(
        public TrialsStatus $status,
        public int $trials,
        public int $passed,
        public int $discards,
        public ?array $failingBuffer = null,
        public ?Outcome $failure = null,
        public ?string $message = null,
    ) {
    }
}
