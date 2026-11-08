<?php

declare(strict_types=1);

namespace Pront\Oracle;

use Pront\Type\UncheckedType;

/**
 * One return-type fragment of a verified target that the return-type oracle cannot check at run
 * time: listed in the run's report as "unchecked" (ADR-0009 section 2), counted on the summary
 * line and an error with `--strict` (ADR-0013).
 */
final readonly class UncheckedFragment
{
    public function __construct(
        public string $target,
        public UncheckedType $fragment,
    ) {
    }

    public function __toString(): string
    {
        return "{$this->target}: return type fragment {$this->fragment->written} is unchecked ({$this->fragment->reason})";
    }
}
