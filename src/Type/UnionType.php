<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * A union of at least two distinct members, none of them a union. Build it with `of()`.
 */
final readonly class UnionType implements Type
{
    /**
     * @param list<Type> $members
     */
    private function __construct(
        public array $members,
    ) {
    }

    /**
     * Flattens nested unions and drops members that describe identically; one member is returned
     * as itself.
     */
    public static function of(Type $first, Type ...$rest): Type
    {
        $members = [];
        foreach ([$first, ...$rest] as $type) {
            foreach ($type instanceof self ? $type->members : [$type] as $member) {
                $members[$member->describe()] ??= $member;
            }
        }

        $members = array_values($members);

        return count($members) === 1 ? $members[0] : new self($members);
    }

    public function describe(): string
    {
        return implode('|', array_map(static fn(Type $type): string => $type->describe(), $this->members));
    }
}
