<?php

declare(strict_types=1);

namespace Pront\Type;

final readonly class VoidType implements Type
{
    public function describe(): string
    {
        return 'void';
    }
}
