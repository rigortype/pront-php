<?php

declare(strict_types=1);

namespace Pront\Tests\Type\Fixtures;

class RestatedUnion
{
    /**
     * @param int|string $x
     * @return static
     */
    public function m(int|string $x): static
    {
        return $this;
    }
}
