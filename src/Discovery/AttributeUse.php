<?php

declare(strict_types=1);

namespace Pront\Discovery;

/**
 * One Pront Attribute as written in source: its resolved name (as written, any case) and whether
 * its condition argument is a closure literal written in the Attribute itself (ADR-0009 section
 * 4, reserved-name conflicts).
 */
final readonly class AttributeUse
{
    public function __construct(
        public string $name,
        public int $line,
        public bool $closureLiteral,
    ) {
    }

    public function is(string $class): bool
    {
        return strcasecmp($this->name, $class) === 0;
    }
}
