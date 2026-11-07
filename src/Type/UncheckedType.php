<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * A fragment outside the Stage 1 list. In a return position it is reported as "unchecked"
 * (ADR-0009 section 2); in an input position the resolver turns it into a pre-run error, so a
 * resolved input type never contains one.
 */
final readonly class UncheckedType implements Type
{
    public function __construct(
        public string $written,
        public string $reason,
    ) {
    }

    public function describe(): string
    {
        return "unchecked({$this->written})";
    }
}
