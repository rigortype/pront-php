<?php

declare(strict_types=1);

namespace Pront\Generator;

use Pront\ChoiceBuffer\ChoiceBuffer;

/**
 * `bool`: both values are edge values (ADR-0007 section 1), so the selector comes first like for
 * every other value with edges.
 */
final readonly class BoolGenerator implements Generator
{
    private const array EDGES = [false, true];

    public function __construct(
        private float $edgeRatio,
    ) {
    }

    public function generate(ChoiceBuffer $buffer): bool
    {
        $edge = $buffer->drawSelector(count(self::EDGES), $this->edgeRatio);

        return $edge === null ? $buffer->drawBool() : self::EDGES[$edge];
    }
}
