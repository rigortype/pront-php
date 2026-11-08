<?php

declare(strict_types=1);

namespace Pront\Tests\Type\Fixtures;

final class Counter
{
    public static int $made = 0;

    public function __construct()
    {
        self::$made++;
    }
}
