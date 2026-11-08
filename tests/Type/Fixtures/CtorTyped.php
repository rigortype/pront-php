<?php

declare(strict_types=1);

namespace Pront\Tests\Type\Fixtures;

class CtorTyped
{
    /**
     * @param positive-int $x
     */
    public function __construct(int $x)
    {
    }
}
