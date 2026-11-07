<?php

declare(strict_types=1);

namespace Pront\Oracle;

use Pront\Type\ArrayShapeType;
use Pront\Type\ArrayType;
use Pront\Type\BoolType;
use Pront\Type\Certainty;
use Pront\Type\ClassType;
use Pront\Type\EnumType;
use Pront\Type\FloatType;
use Pront\Type\IntType;
use Pront\Type\ListType;
use Pront\Type\LiteralType;
use Pront\Type\MixedType;
use Pront\Type\NeverType;
use Pront\Type\NullType;
use Pront\Type\SelfType;
use Pront\Type\StaticType;
use Pront\Type\StringType;
use Pront\Type\Type;
use Pront\Type\UncheckedType;
use Pront\Type\UnionType;
use Pront\Type\VoidType;

/**
 * The runtime checker of the return-type oracle (ADR-0003 item 5): does a returned value belong to
 * the declared return type? It covers the Stage 1 checked set (ADR-0014 section 3): the input
 * types, `void`, `never`, `null`, `mixed`, `self`, `static` and non-generic class or interface
 * names. An unchecked fragment answers `Maybe`; those fragments are listed in the run's report
 * (`Oracle::uncheckedFragments()`), so a `Maybe` is never a silent pass.
 *
 * The check follows what PHPStan's return-type rule accepts in a strict-types file: `float`
 * accepts an int (as PHPStan's `FloatType::accepts()` and PHP's int-to-float widening do), an
 * `array<string, V>` does not accept an int key, `void` accepts only null (what a `void` call
 * yields), and `never` accepts nothing. A `Stringable` object is not a `string`, even where a
 * non-strict file would let PHPStan accept it.
 */
final class ReturnTypeCheck
{
    /**
     * @param class-string $static the late-bound class `static` stands for: the receiver's class,
     *     or the class of the method for a static method
     */
    public static function check(Type $type, mixed $value, string $static): Certainty
    {
        return match (true) {
            $type instanceof UncheckedType => Certainty::Maybe,
            $type instanceof MixedType => Certainty::Yes,
            $type instanceof NeverType => Certainty::No,
            $type instanceof VoidType, $type instanceof NullType => Certainty::of($value === null),
            $type instanceof UnionType => Certainty::any(array_map(
                static fn(Type $member): Certainty => self::check($member, $value, $static),
                $type->members,
            )),
            $type instanceof BoolType => Certainty::of(is_bool($value)),
            $type instanceof IntType => Certainty::of(is_int($value) && $type->min <= $value && $value <= $type->max),
            $type instanceof FloatType => Certainty::of(is_float($value) || is_int($value)),
            $type instanceof StringType => Certainty::of(is_string($value) && (!$type->nonEmpty || $value !== '')),
            $type instanceof LiteralType => Certainty::of($value === $type->value),
            $type instanceof ListType => self::list($type, $value, $static),
            $type instanceof ArrayType => self::array($type, $value, $static),
            $type instanceof ArrayShapeType => self::shape($type, $value, $static),
            $type instanceof EnumType, $type instanceof ClassType => Certainty::of($value instanceof $type->name),
            $type instanceof SelfType => Certainty::of($value instanceof $type->class),
            $type instanceof StaticType => Certainty::of($value instanceof $static),
            default => throw new \LogicException('No runtime check for ' . $type::class),
        };
    }

    /**
     * @param class-string $static
     */
    private static function list(ListType $type, mixed $value, string $static): Certainty
    {
        if (!is_array($value) || !array_is_list($value) || ($type->nonEmpty && $value === [])) {
            return Certainty::No;
        }

        return self::each($value, static fn(mixed $v): Certainty => self::check($type->value, $v, $static));
    }

    /**
     * @param class-string $static
     */
    private static function array(ArrayType $type, mixed $value, string $static): Certainty
    {
        if (!is_array($value)) {
            return Certainty::No;
        }
        $result = Certainty::Yes;
        foreach ($value as $key => $item) {
            $answer = Certainty::all([self::check($type->key, $key, $static), self::check($type->value, $item, $static)]);
            if ($answer === Certainty::No) {
                return Certainty::No;
            }
            if ($answer === Certainty::Maybe) {
                $result = Certainty::Maybe;
            }
        }

        return $result;
    }

    /**
     * A sealed shape with required keys only (ADR-0003 item 3): exactly the declared keys, in any
     * order.
     *
     * @param class-string $static
     */
    private static function shape(ArrayShapeType $type, mixed $value, string $static): Certainty
    {
        if (!is_array($value) || count($value) !== count($type->items)) {
            return Certainty::No;
        }
        $answers = [];
        foreach ($type->items as $key => $item) {
            if (!array_key_exists($key, $value)) {
                return Certainty::No;
            }
            $answers[] = self::check($item, $value[$key], $static);
        }

        return Certainty::all($answers);
    }

    /**
     * @param array<mixed> $values
     * @param \Closure(mixed): Certainty $check
     */
    private static function each(array $values, \Closure $check): Certainty
    {
        $result = Certainty::Yes;
        foreach ($values as $value) {
            $answer = $check($value);
            if ($answer === Certainty::No) {
                return Certainty::No;
            }
            if ($answer === Certainty::Maybe) {
                $result = Certainty::Maybe;
            }
        }

        return $result;
    }
}
