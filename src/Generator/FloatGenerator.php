<?php

declare(strict_types=1);

namespace Pront\Generator;

use Pront\ChoiceBuffer\ChoiceBuffer;

/**
 * `float`: one float draw. The float draw's own `kind` slot is its edge-or-uniform selector
 * (ADR-0002 section 2), so there is no separate selector draw; the edge ratio is the fresh
 * probability of `kind` 2.
 */
final readonly class FloatGenerator implements Generator
{
    public function __construct(
        private float $edgeRatio,
    ) {
    }

    public function generate(ChoiceBuffer $buffer): float
    {
        return $buffer->drawFloat($this->edgeRatio);
    }
}
