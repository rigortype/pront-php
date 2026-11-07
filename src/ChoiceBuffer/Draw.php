<?php

declare(strict_types=1);

namespace Pront\ChoiceBuffer;

/**
 * One consumed draw: its kind and the slots the generator actually read (zero-filled slots written
 * out).
 */
final readonly class Draw
{
    /**
     * @param list<int<0, max>> $slots
     */
    public function __construct(
        public DrawKind $kind,
        public array $slots,
    ) {
    }
}
