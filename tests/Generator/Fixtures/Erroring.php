<?php

declare(strict_types=1);

namespace Pront\Tests\Generator\Fixtures;

final class Erroring
{
    public function __construct()
    {
        throw new \TypeError('constructor bug');
    }
}
