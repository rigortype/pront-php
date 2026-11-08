<?php

declare(strict_types=1);

namespace Pront\Tests\Generator;

use Pront\Type\ArrayShapeType;
use Pront\Type\ArrayType;
use Pront\Type\BoolType;
use Pront\Type\EnumType;
use Pront\Type\FloatType;
use Pront\Type\IntType;
use Pront\Type\ListType;
use Pront\Type\LiteralType;
use Pront\Type\NullType;
use Pront\Type\StringType;
use Pront\Type\Type;
use Pront\Type\UnionType;

/**
 * An independent check that a value is in a Stage 1 input type, written from the type's meaning
 * rather than from the generators.
 */
final class Membership
{
    public static function holds(Type $type, mixed $value): bool
    {
        return match (true) {
            $type instanceof IntType => is_int($value) && $value >= $type->min && $value <= $type->max,
            $type instanceof FloatType => is_float($value),
            $type instanceof BoolType => is_bool($value),
            $type instanceof StringType => is_string($value) && (!$type->nonEmpty || $value !== ''),
            $type instanceof LiteralType => $value === $type->value,
            $type instanceof NullType => $value === null,
            $type instanceof EnumType => $value instanceof $type->name,
            $type instanceof UnionType => array_any($type->members, static fn(Type $m): bool => self::holds($m, $value)),
            $type instanceof ListType => is_array($value) && array_is_list($value)
                && (!$type->nonEmpty || $value !== [])
                && array_all($value, static fn(mixed $v): bool => self::holds($type->value, $v)),
            $type instanceof ArrayType => is_array($value)
                && array_all($value, static fn(mixed $v, int|string $k): bool => self::holds($type->key, $k) && self::holds($type->value, $v)),
            $type instanceof ArrayShapeType => is_array($value)
                && array_keys($value) === array_keys($type->items)
                && array_all($type->items, static fn(Type $t, int|string $k): bool => self::holds($t, $value[$k])),
            default => throw new \LogicException("no membership check for {$type->describe()}"),
        };
    }
}
