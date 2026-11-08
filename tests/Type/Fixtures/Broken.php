<?php

declare(strict_types=1);

namespace Pront\Tests\Type\Fixtures;

class Broken
{
    /**
     * @param NoSuchClass $x
     */
    public function m(int $x): void
    {
    }
}
