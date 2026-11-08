<?php

declare(strict_types=1);

namespace Pront\Tests\Type\Fixtures;

class Constructed
{
    /**
     * @param positive-int $b
     */
    public function __construct(int $b, int $z)
    {
    }
}
