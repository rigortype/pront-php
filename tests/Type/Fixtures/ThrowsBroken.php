<?php

declare(strict_types=1);

namespace Pront\Tests\Type\Fixtures;

interface ThrowsBroken
{
    /**
     * @throws NoSuchClass
     */
    public function m(): void;
}
