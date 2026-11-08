<?php

declare(strict_types=1);

namespace Pront\Tests\Type\Fixtures;

class WiderUnion
{
    /**
     * @return non-empty-string|positive-int
     */
    public function a(): int|string
    {
        return 1;
    }

    /**
     * @return string|positive-int
     */
    public function b(): int|string
    {
        return 1;
    }

    /**
     * @return ?non-empty-string
     */
    public function c(): ?string
    {
        return null;
    }

    /**
     * @return non-falsy-string
     */
    public function d(): string
    {
        return 'x';
    }
}
