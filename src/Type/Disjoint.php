<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * Whether two types share no value: the relation PHPStan uses to keep a native union member next
 * to a narrowing PHPDoc type (`TypehintHelper::decideType` keeps a member whose
 * `isSuperTypeOf(phpDoc)` is No). `Maybe` when a fragment outside the Stage 1 list makes it
 * undecidable.
 */
final class Disjoint
{
    public static function check(Type $a, Type $b): Certainty
    {
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            if ($x instanceof UnionType) {
                return Certainty::all(array_map(static fn(Type $m): Certainty => self::check($m, $y), $x->members));
            }
        }
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            if ($x instanceof NeverType || $x instanceof VoidType) {
                return Certainty::Yes;
            }
            if ($x instanceof MixedType) {
                return Certainty::No;
            }
        }
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            if ($x instanceof UncheckedType) {
                return self::outsideList($x, $y);
            }
        }

        $kindA = self::kind($a);
        if ($kindA !== self::kind($b)) {
            return Certainty::Yes;
        }

        return match ($kindA) {
            'int' => Certainty::of(self::intRange($a)[1] < self::intRange($b)[0] || self::intRange($b)[1] < self::intRange($a)[0]),
            'float', 'bool' => Certainty::of($a instanceof LiteralType && $b instanceof LiteralType && $a->value !== $b->value),
            'string' => self::strings($a, $b),
            'class' => self::classes((string) Subtype::className($a), (string) Subtype::className($b)),
            // Empty arrays belong to every array-like type but non-empty lists; native members
            // are only ever the plain `array`, which overlaps every array-like type.
            default => Certainty::No,
        };
    }

    /**
     * The native `iterable` (array or `Traversable`) and `object`; anything else is undecidable.
     */
    private static function outsideList(UncheckedType $native, Type $other): Certainty
    {
        if ($other instanceof UncheckedType) {
            return Certainty::Maybe;
        }

        $class = Subtype::className($other);

        return match (true) {
            $native->written === 'object' => Certainty::of($class === null),
            $native->written === 'iterable' && Subtype::isArrayLike($other) => Certainty::No,
            $native->written === 'iterable' && $class !== null => self::classes($class, \Traversable::class),
            $native->written === 'iterable' => Certainty::Yes,
            default => Certainty::Maybe,
        };
    }

    private static function kind(Type $type): string
    {
        return match (true) {
            $type instanceof IntType, $type instanceof LiteralType && is_int($type->value) => 'int',
            $type instanceof FloatType, $type instanceof LiteralType && is_float($type->value) => 'float',
            $type instanceof StringType, $type instanceof LiteralType && is_string($type->value) => 'string',
            $type instanceof BoolType, $type instanceof LiteralType && is_bool($type->value) => 'bool',
            $type instanceof NullType => 'null',
            Subtype::isArrayLike($type) => 'array',
            Subtype::className($type) !== null => 'class',
            default => get_class($type),
        };
    }

    /**
     * @return array{int, int}
     */
    private static function intRange(Type $type): array
    {
        return $type instanceof IntType ? [$type->min, $type->max] : [(int) $type->value, (int) $type->value];
    }

    private static function strings(Type $a, Type $b): Certainty
    {
        if ($a instanceof LiteralType && $b instanceof LiteralType) {
            return Certainty::of($a->value !== $b->value);
        }
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            if ($x instanceof LiteralType && $y instanceof StringType) {
                return Certainty::of($y->nonEmpty && $x->value === '');
            }
        }

        return Certainty::No;
    }

    /**
     * Two class-likes share an instance unless neither extends the other and a common subclass is
     * impossible: one of them is final (enums are), or both are classes (single inheritance).
     */
    private static function classes(string $a, string $b): Certainty
    {
        if (is_a($a, $b, true) || is_a($b, $a, true)) {
            return Certainty::No;
        }

        $ra = new \ReflectionClass($a);
        $rb = new \ReflectionClass($b);

        return Certainty::of(
            $ra->isFinal() || $rb->isFinal() || (!$ra->isInterface() && !$rb->isInterface()),
        );
    }
}
