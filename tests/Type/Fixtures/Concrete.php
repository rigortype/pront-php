<?php

declare(strict_types=1);

namespace Pront\Tests\Type\Fixtures;

trait Concrete
{
    /**
     * @param positive-int $x
     */
    public function m(int $x): void
    {
    }
}
