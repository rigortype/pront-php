<?php

declare(strict_types=1);

namespace Pront\Tests\Generator\Fixtures;

final class Asserting
{
    public function __construct()
    {
        throw new \AssertionError('assert($n > 0)');
    }
}
