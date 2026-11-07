<?php

declare(strict_types=1);

namespace Pront\Generator;

use Pront\ChoiceBuffer\ChoiceBuffer;

/**
 * A union. When `null` is a member, the nullable draw comes first (`null` is the simplest,
 * ADR-0002 section 2); then, among the other members, an index draw in declaration order when there
 * is more than one, and that member's generator.
 */
final readonly class UnionGenerator implements Generator
{
    /**
     * @param non-empty-list<Generator> $members the members other than `null`, in declaration order
     */
    public function __construct(
        private bool $nullable,
        private array $members,
    ) {
    }

    public function generate(ChoiceBuffer $buffer): mixed
    {
        if ($this->nullable && $buffer->drawIsNull()) {
            return null;
        }
        $count = count($this->members);

        return $this->members[$count === 1 ? 0 : $buffer->drawIndex($count)]->generate($buffer);
    }
}
