<?php

declare(strict_types=1);

namespace Pront\Tests\Type\Fixtures;

class Documented
{
    /**
     * @param positive-int $n
     * @param non-empty-string $s
     * @return non-empty-string
     * @throws \LogicException
     */
    public function m(int $n, string $s): string
    {
        return str_repeat($s, $n);
    }

    /**
     * @param positive-int $n
     */
    private function hidden(int $n): void
    {
    }
}
