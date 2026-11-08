<?php

declare(strict_types=1);

namespace Pront\Tests\Type\Fixtures;

/**
 * @phpstan-type TraitMoney int<0, max>
 */
trait WithAlias
{
    /**
     * @return TraitMoney
     */
    public function m(): int
    {
        return 0;
    }
}
