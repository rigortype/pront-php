<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * A non-generic class or interface name (not an enum), fully qualified. Checked in a return
 * position; a pre-run error in an input position until Stage 2 (ADR-0014 section 3).
 */
final readonly class ClassType implements Type
{
    /**
     * @param class-string $name
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
