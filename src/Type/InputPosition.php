<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * Which resolved types may stand in an input position in Stage 1 (ADR-0014 section 3): the Stage 1
 * list only. `mixed`, `void`, `never`, class types, `self`, `static`, a lone `null` and any
 * unchecked fragment are pre-run errors there.
 */
final class InputPosition
{
    /**
     * @return list<string> one message per offending fragment; empty when the type is generatable
     */
    public static function problems(Type $type): array
    {
        return array_map(
            static fn(array $problem): string => $problem[0] === $type
                ? $problem[1]
                : "{$problem[1]} (in {$type->describe()})",
            self::fragments($type, false),
        );
    }

    /**
     * @return list<array{Type, string}>
     */
    private static function fragments(Type $type, bool $inUnion): array
    {
        $message = match (true) {
            $type instanceof UncheckedType => "{$type->written}: {$type->reason}",
            $type instanceof MixedType => 'mixed cannot be generated (ADR-0010 section 1); declare a narrower type',
            $type instanceof VoidType, $type instanceof NeverType => "{$type->describe()} is not an input type",
            $type instanceof ClassType, $type instanceof SelfType, $type instanceof StaticType =>
                "class type {$type->describe()} cannot be generated until Stage 2",
            $type instanceof EnumType && (new \ReflectionEnum($type->name))->getCases() === [] =>
                "enum {$type->name} has no cases, so no value can be generated",
            $type instanceof NullType && !$inUnion => 'null alone is not an input type in Stage 1 (use ?T or a union)',
            $type instanceof ArrayType && $type->describe() === 'array' =>
                'array has no element type; declare one in PHPDoc (list<T>, array<K, V> or an array shape)',
            default => null,
        };
        if ($message !== null) {
            return [[$type, $message]];
        }

        $children = match (true) {
            $type instanceof UnionType => $type->members,
            $type instanceof ListType => [$type->value],
            $type instanceof ArrayType => [$type->key, $type->value],
            $type instanceof ArrayShapeType => array_values($type->items),
            default => [],
        };

        return array_merge([], ...array_map(
            static fn(Type $child): array => self::fragments($child, $type instanceof UnionType),
            $children,
        ));
    }
}
