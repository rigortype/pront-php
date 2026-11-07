<?php

declare(strict_types=1);

namespace Pront\Tests\Type\Fixtures;

class Untagged
{
    /**
     * A description, and no tags.
     */
    public function m(int|string $x): mixed
    {
        return null;
    }
}
