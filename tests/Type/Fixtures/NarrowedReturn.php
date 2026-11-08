<?php

declare(strict_types=1);

namespace Pront\Tests\Type\Fixtures;

class NarrowedReturn
{
    /**
     * @return positive-int
     */
    public function m(): ?int
    {
        return 1;
    }
}
