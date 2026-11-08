<?php

declare(strict_types=1);

namespace Pront\Tests\Generator\Fixtures;

final class Needy
{
    public function __construct(public \Countable $items)
    {
    }
}
