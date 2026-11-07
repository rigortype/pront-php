<?php

declare(strict_types=1);

namespace Pront\Generator;

use Pront\ChoiceBuffer\ChoiceBuffer;

/**
 * A sealed array shape with required keys: each value in declaration order, no other draw.
 */
final readonly class ShapeGenerator implements Generator
{
    /**
     * @param array<int|string, Generator> $items
     */
    public function __construct(
        private array $items,
    ) {
    }

    /**
     * @return array<int|string, mixed>
     */
    public function generate(ChoiceBuffer $buffer): array
    {
        $shape = [];
        foreach ($this->items as $key => $item) {
            $shape[$key] = $item->generate($buffer);
        }

        return $shape;
    }
}
