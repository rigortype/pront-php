<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * `static`, resolved against the declaring class (the late-bound class is known only at run time).
 */
final readonly class StaticType implements Type
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
        return "static({$this->class})";
    }
}
