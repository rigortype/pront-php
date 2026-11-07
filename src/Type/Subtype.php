<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * The narrowing check of ADR-0003 item 4: is a PHPDoc type a subtype of the native type? Implemented
 * for the Stage 1 list only; a fragment outside it answers `Maybe`.
 */
final class Subtype
{
    public static function check(Type $sub, Type $super): Certainty
    {
        if ($super instanceof MixedType || $sub instanceof NeverType) {
            return Certainty::Yes;
        }

        if ($sub instanceof UnionType) {
            return Certainty::all(array_map(static fn(Type $m): Certainty => self::check($m, $super), $sub->members));
        }

        if ($sub instanceof BoolType && $super instanceof UnionType) {
            return self::check(UnionType::of(new LiteralType(true), new LiteralType(false)), $super);
        }

        if ($super instanceof UnionType) {
            return Certainty::any(array_map(static fn(Type $m): Certainty => self::check($sub, $m), $super->members));
        }

        if ($super instanceof UncheckedType && !$sub instanceof UncheckedType) {
            return self::intoNativeOutsideList($sub, $super);
        }

        if ($sub instanceof UncheckedType) {
            return Certainty::Maybe;
        }

        return self::atomic($sub, $super);
    }

    private static function atomic(Type $sub, Type $super): Certainty
    {
        return match (true) {
            $super instanceof IntType => Certainty::of(
                ($sub instanceof IntType && $super->min <= $sub->min && $sub->max <= $super->max)
                || ($sub instanceof LiteralType && is_int($sub->value)
                    && $super->min <= $sub->value && $sub->value <= $super->max),
            ),
            $super instanceof FloatType => Certainty::of(
                $sub instanceof FloatType || ($sub instanceof LiteralType && is_float($sub->value)),
            ),
            $super instanceof BoolType => Certainty::of(
                $sub instanceof BoolType || ($sub instanceof LiteralType && is_bool($sub->value)),
            ),
            $super instanceof StringType => Certainty::of(
                ($sub instanceof StringType && ($sub->nonEmpty || !$super->nonEmpty))
                || ($sub instanceof LiteralType && is_string($sub->value) && (!$super->nonEmpty || $sub->value !== '')),
            ),
            $super instanceof LiteralType => Certainty::of($sub instanceof LiteralType && $sub->value === $super->value),
            $super instanceof NullType => Certainty::of($sub instanceof NullType),
            $super instanceof VoidType => Certainty::of($sub instanceof VoidType),
            $super instanceof NeverType => Certainty::No,
            $super instanceof ListType => self::intoList($sub, $super),
            $super instanceof ArrayType => self::intoArray($sub, $super),
            $super instanceof ArrayShapeType => self::intoShape($sub, $super),
            $super instanceof EnumType => Certainty::of($sub instanceof EnumType && $sub->name === $super->name),
            $super instanceof ClassType, $super instanceof SelfType => self::intoClass(
                $sub,
                $super instanceof ClassType ? $super->name : $super->class,
            ),
            $super instanceof StaticType => Certainty::of(
                $sub instanceof StaticType && is_a($sub->class, $super->class, true),
            ),
            default => Certainty::No,
        };
    }

    /**
     * The native `iterable` and `object` are outside the Stage 1 list, but whether a Stage 1 type
     * narrows them is still decidable: every array-like type is iterable, every class-like type
     * an object.
     */
    private static function intoNativeOutsideList(Type $sub, UncheckedType $super): Certainty
    {
        $arrayLike = $sub instanceof ListType || $sub instanceof ArrayType || $sub instanceof ArrayShapeType;
        $classLike = $sub instanceof ClassType || $sub instanceof EnumType
            || $sub instanceof SelfType || $sub instanceof StaticType;

        return match (true) {
            $super->written === 'iterable' && $arrayLike, $super->written === 'object' && $classLike => Certainty::Yes,
            $super->written === 'iterable', $super->written === 'object' => Certainty::No,
            default => Certainty::Maybe,
        };
    }

    private static function intoList(Type $sub, ListType $super): Certainty
    {
        if ($sub instanceof ListType) {
            if ($super->nonEmpty && !$sub->nonEmpty) {
                return Certainty::No;
            }

            return self::check($sub->value, $super->value);
        }

        if ($sub instanceof ArrayShapeType) {
            if (!array_is_list($sub->items) || ($super->nonEmpty && $sub->items === [])) {
                return Certainty::No;
            }

            return Certainty::all(array_map(static fn(Type $v): Certainty => self::check($v, $super->value), $sub->items));
        }

        return Certainty::No;
    }

    private static function intoArray(Type $sub, ArrayType $super): Certainty
    {
        return match (true) {
            $sub instanceof ArrayType => Certainty::all([
                self::check($sub->key, $super->key),
                self::check($sub->value, $super->value),
            ]),
            $sub instanceof ListType => Certainty::all([
                self::check(new IntType(0), $super->key),
                self::check($sub->value, $super->value),
            ]),
            $sub instanceof ArrayShapeType => Certainty::all(array_merge(
                array_map(static fn(int|string $k): Certainty => self::check(new LiteralType($k), $super->key), array_keys($sub->items)),
                array_map(static fn(Type $v): Certainty => self::check($v, $super->value), array_values($sub->items)),
            )),
            default => Certainty::No,
        };
    }

    private static function intoShape(Type $sub, ArrayShapeType $super): Certainty
    {
        if (!$sub instanceof ArrayShapeType || count($sub->items) !== count($super->items)) {
            return Certainty::No;
        }

        $answers = [];
        foreach ($super->items as $key => $type) {
            if (!array_key_exists($key, $sub->items)) {
                return Certainty::No;
            }
            $answers[] = self::check($sub->items[$key], $type);
        }

        return Certainty::all($answers);
    }

    private static function intoClass(Type $sub, string $class): Certainty
    {
        $name = match (true) {
            $sub instanceof ClassType, $sub instanceof EnumType => $sub->name,
            $sub instanceof SelfType, $sub instanceof StaticType => $sub->class,
            default => null,
        };

        return Certainty::of($name !== null && is_a($name, $class, true));
    }
}
