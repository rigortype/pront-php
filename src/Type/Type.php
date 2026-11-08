<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * A type in Pront's own Stage 1 model (ADR-0003). Value objects only; the vocabulary is PHPStan's
 * (ADR-0009 section 2), the classes are Pront's.
 */
interface Type
{
    /**
     * The type written back in PHPStan notation, for error messages and reports.
     */
    public function describe(): string;
}
