<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * `string`, or `non-empty-string` when `$nonEmpty`.
 */
final readonly class StringType implements Type
{
    public function __construct(
        public bool $nonEmpty = false,
    ) {
    }

    public function describe(): string
    {
        return $this->nonEmpty ? 'non-empty-string' : 'string';
    }
}
