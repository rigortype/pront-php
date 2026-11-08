<?php

declare(strict_types=1);

namespace Pront\Tests\Type\Fixtures;

interface DocumentedContract
{
    /**
     * @phpstan-param positive-int $n
     */
    public function m(int $n): string;
}
