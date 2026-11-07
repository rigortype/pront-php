<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * `mixed`: checked in a return position, never generatable (ADR-0010 section 1).
 */
final readonly class MixedType implements Type
{
    public function describe(): string
    {
        return 'mixed';
    }
}
