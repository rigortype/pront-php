<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * `null`. A nullable type `?T` is the union `T|null`.
 */
final readonly class NullType implements Type
{
    public function describe(): string
    {
        return 'null';
    }
}
