<?php

declare(strict_types=1);

namespace Pront\Tests\Type\Fixtures;

/**
 * @template X
 */
trait InnerTemplated
{
    /**
     * @return X
     */
    public function m(): mixed
    {
        return null;
    }
}
