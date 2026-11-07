<?php

declare(strict_types=1);

namespace Pront\Generator;

use Pront\ChoiceBuffer\ChoiceBuffer;

/**
 * A type with exactly one value (a literal, or `null` inside a union): no draw is needed.
 */
final readonly class ConstantGenerator implements Generator
{
    public function __construct(
        private int|float|string|bool|null $value,
    ) {
    }

    public function generate(ChoiceBuffer $buffer): int|float|string|bool|null
    {
        return $this->value;
    }
}
