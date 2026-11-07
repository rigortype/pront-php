<?php

declare(strict_types=1);

namespace Pront\Tests\Generator\Fixtures;

final class Holder
{
    /**
     * @param int<0, 9> $digit
     * @param ?non-empty-string $label
     */
    public function __construct(public int $digit, public ?string $label)
    {
    }
}
