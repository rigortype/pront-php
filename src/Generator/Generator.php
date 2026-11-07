<?php

declare(strict_types=1);

namespace Pront\Generator;

use Pront\ChoiceBuffer\ChoiceBuffer;

/**
 * Produces one value per call, drawing only through the choice buffer (ADR-0002, ADR-0011 section
 * 1), so a replayed or shrunk buffer reproduces the value.
 */
interface Generator
{
    /**
     * @throws Rejected when the drawn candidate is not a valid input; the trial is a discard
     * @throws \Pront\ChoiceBuffer\InvalidChoice when a replayed slot is not a valid position
     */
    public function generate(ChoiceBuffer $buffer): mixed;
}
