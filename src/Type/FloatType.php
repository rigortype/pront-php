<?php

declare(strict_types=1);

namespace Pront\Type;

final readonly class FloatType implements Type
{
    public function describe(): string
    {
        return 'float';
    }
}
