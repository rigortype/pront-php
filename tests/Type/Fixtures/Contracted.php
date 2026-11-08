<?php

declare(strict_types=1);

namespace Pront\Tests\Type\Fixtures;

trait Contracted
{
    /**
     * @param positive-int $x
     */
    abstract public function m(int $x): void;
}
