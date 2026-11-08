<?php

declare(strict_types=1);

namespace Pront\Type;

final readonly class BoolType implements Type
{
    public function describe(): string
    {
        return 'bool';
    }
}
