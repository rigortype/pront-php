<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * A backed or unit enum, by fully qualified name.
 */
final readonly class EnumType implements Type
{
    /**
     * @param class-string<\UnitEnum> $name
     */
    public function __construct(
        public string $name,
    ) {
    }

    public function describe(): string
    {
        return $this->name;
    }
}
