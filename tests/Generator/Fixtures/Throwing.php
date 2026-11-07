<?php

declare(strict_types=1);

namespace Pront\Tests\Generator\Fixtures;

final class Throwing
{
    public function __construct(public int $n)
    {
        if ($n % 2 !== 0) {
            throw new \DomainException("odd {$n}");
        }
    }
}
