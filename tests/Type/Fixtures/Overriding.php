<?php

declare(strict_types=1);

namespace Pront\Tests\Type\Fixtures;

class Overriding extends Documented
{
    public function m(int $n, string $s): string
    {
        return '';
    }
}
