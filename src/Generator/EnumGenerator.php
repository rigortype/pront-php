<?php

declare(strict_types=1);

namespace Pront\Generator;

use Pront\ChoiceBuffer\ChoiceBuffer;

/**
 * A backed or unit enum: one index draw over the cases in declaration order (ADR-0002 section 2).
 */
final readonly class EnumGenerator implements Generator
{
    /** @var non-empty-list<\UnitEnum> */
    private array $cases;

    /**
     * @param class-string<\UnitEnum> $enum
     */
    public function __construct(string $enum)
    {
        $cases = $enum::cases();
        if ($cases === []) {
            throw new UngeneratableType("enum {$enum} has no cases, so no value can be generated");
        }
        $this->cases = $cases;
    }

    public function generate(ChoiceBuffer $buffer): \UnitEnum
    {
        return $this->cases[$buffer->drawIndex(count($this->cases))];
    }
}
