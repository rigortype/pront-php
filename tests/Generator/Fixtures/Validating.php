<?php

declare(strict_types=1);

namespace Pront\Tests\Generator\Fixtures;

final class Validating
{
    public function __construct()
    {
        str_repeat('a', -1);
    }
}
