<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * `self`, resolved against the declaring class.
 */
final readonly class SelfType implements Type
{
    /**
     * @param class-string $class
     */
    public function __construct(
        public string $class,
    ) {
    }

    public function describe(): string
    {
        return "self({$this->class})";
    }
}
