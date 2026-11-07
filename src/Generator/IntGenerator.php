<?php

declare(strict_types=1);

namespace Pront\Generator;

use Pront\ChoiceBuffer\ChoiceBuffer;

/**
 * `int<min, max>`: the selector, then either an in-range edge value or a uniform `int` draw.
 */
final readonly class IntGenerator implements Generator
{
    /** @var list<int> */
    private array $edges;

    public function __construct(
        private int $min,
        private int $max,
        private float $edgeRatio,
    ) {
        $this->edges = EdgeValues::ints($min, $max);
    }

    public function generate(ChoiceBuffer $buffer): int
    {
        $edge = $buffer->drawSelector(count($this->edges), $this->edgeRatio);

        return $edge === null ? $buffer->drawInt($this->min, $this->max) : $this->edges[$edge];
    }
}
