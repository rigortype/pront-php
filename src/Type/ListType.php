<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * `list<T>`, or `non-empty-list<T>` when `$nonEmpty`.
 */
final readonly class ListType implements Type
{
    public function __construct(
        public Type $value,
        public bool $nonEmpty = false,
    ) {
    }

    public function describe(): string
    {
        return ($this->nonEmpty ? 'non-empty-list' : 'list') . '<' . $this->value->describe() . '>';
    }
}
