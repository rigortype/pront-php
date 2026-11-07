<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * `never`. A target declared `never` has undefined behaviour (ADR-0003 item 4, ADR-0004 item 7);
 * the resolver only represents the declaration.
 */
final readonly class NeverType implements Type
{
    public function describe(): string
    {
        return 'never';
    }
}
