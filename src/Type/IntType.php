<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * `int`, `int<min, max>` and the named ranges (`positive-int`, `negative-int`, `non-negative-int`).
 * Bounds are inclusive; `min`/`max` are `PHP_INT_MIN`/`PHP_INT_MAX`.
 */
final readonly class IntType implements Type
{
    public function __construct(
        public int $min = PHP_INT_MIN,
        public int $max = PHP_INT_MAX,
    ) {
        if ($min > $max) {
            throw new \InvalidArgumentException("Empty integer range [{$min}, {$max}]");
        }
    }

    public function describe(): string
    {
        if ($this->min === PHP_INT_MIN && $this->max === PHP_INT_MAX) {
            return 'int';
        }

        $min = $this->min === PHP_INT_MIN ? 'min' : (string) $this->min;
        $max = $this->max === PHP_INT_MAX ? 'max' : (string) $this->max;

        return "int<{$min}, {$max}>";
    }
}
