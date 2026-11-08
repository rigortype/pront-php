<?php

declare(strict_types=1);

namespace Pront\Shrinker;

use Pront\ChoiceBuffer\Draw;

/**
 * The shortlex-smallest failing consumed buffer found, and how the search went.
 */
final readonly class ShrinkResult
{
    /**
     * @param list<Draw> $buffer the best consumed buffer; the input buffer when nothing was accepted
     * @param ?Outcome $failure the outcome of the first evaluation, the replay of the input buffer,
     *                         which every accepted candidate reproduced; null when the budget allowed
     *                         no evaluation
     * @param int $evaluations evaluations run, the replay of the input buffer included (each is one
     *                         attempt, ADR-0004)
     * @param int $accepted candidates accepted as a new best
     */
    public function __construct(
        public array $buffer,
        public ?Outcome $failure,
        public int $evaluations,
        public int $accepted,
        public StopReason $stopReason,
    ) {
    }

    /**
     * The best buffer as plain slot lists, the form `ChoiceBuffer::replay()` accepts.
     *
     * @return list<list<int<0, max>>>
     */
    public function slots(): array
    {
        return array_map(static fn(Draw $draw): array => $draw->slots, $this->buffer);
    }
}
