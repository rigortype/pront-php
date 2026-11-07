<?php

declare(strict_types=1);

namespace Pront\Generator;

use Pront\ChoiceBuffer\ChoiceBuffer;

/**
 * The length of a list or array: the selector over the length edge values of ADR-0007 section 1,
 * then a uniform length draw when it selects none. With no stated maximum the maximum is
 * `ChoiceBuffer::DEFAULT_MAX_LENGTH`, or the minimum when that is larger.
 */
final readonly class Lengths
{
    /** @var list<int> */
    private array $edges;

    public function __construct(
        private int $min,
        private float $edgeRatio,
    ) {
        $this->edges = EdgeValues::lengths($min, max(ChoiceBuffer::DEFAULT_MAX_LENGTH, $min));
    }

    public function draw(ChoiceBuffer $buffer): int
    {
        $edge = $buffer->drawSelector(count($this->edges), $this->edgeRatio);

        return $edge === null ? $buffer->drawLength($this->min) : $this->edges[$edge];
    }
}
