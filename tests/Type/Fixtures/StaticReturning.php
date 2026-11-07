<?php

declare(strict_types=1);

namespace Pront\Tests\Type\Fixtures;

class StaticReturning
{
    /**
     * @return static
     */
    public function m(): ?self
    {
        return $this;
    }
}
