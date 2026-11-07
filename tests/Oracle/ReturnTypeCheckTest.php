<?php

declare(strict_types=1);

namespace Pront\Tests\Oracle;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pront\Oracle\ReturnTypeCheck;
use Pront\Tests\Type\Fixtures\BackedSuit;
use Pront\Tests\Type\Fixtures\Circle;
use Pront\Tests\Type\Fixtures\Sub\Square;
use Pront\Tests\Type\Fixtures\Suit;
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

final class ReturnTypeCheckTest extends TestCase
{
    /**
     * Every type of the Stage 1 checked set (ADR-0014 section 3), each with values it accepts and
     * values it rejects.
     *
     * @return iterable<string, array{Type, mixed, Certainty}>
     */
    public static function cases(): iterable
    {
        $unchecked = new UncheckedType('callable(int): string', 'not in the Stage 1 list');
        $intOrString = UnionType::of(new IntType(), new StringType());
        $table = [
            'bool' => [new BoolType(), [true, false], [0, 1, null, 'true']],
            'int' => [new IntType(), [0, PHP_INT_MIN, PHP_INT_MAX], [0.0, '0', null, true]],
            'int range' => [new IntType(1, 10), [1, 10], [0, 11, 5.0]],
            'positive-int' => [new IntType(1), [1, PHP_INT_MAX], [0, -1]],
            'negative-int' => [new IntType(PHP_INT_MIN, -1), [-1, PHP_INT_MIN], [0, 1]],
            'non-negative-int' => [new IntType(0), [0, 1], [-1]],
            'float (accepts an int, as PHPStan does)' => [new FloatType(), [0.0, -0.0, NAN, INF, -INF, 0, PHP_INT_MAX], ['0.0', null, true]],
            'list<float>' => [new ListType(new FloatType()), [[1, 1.5]], [['1.5']]],
            'string' => [new StringType(), ['', 'a', "\0"], [0, null, ['a']]],
            'non-empty-string' => [new StringType(true), ['0', 'a'], ['', 0]],
            'int literal' => [new LiteralType(5), [5], [5.0, '5', 6]],
            'float literal' => [new LiteralType(1.5), [1.5], [1, '1.5', NAN]],
            'integral float literal (accepts the same int, as PHPStan lets through)' => [new LiteralType(1.0), [1.0, 1], [2, '1', true]],
            'string literal' => [new LiteralType('a'), ['a'], ['A', 'a ', 0]],
            'true literal' => [new LiteralType(true), [true], [false, 1]],
            'false literal' => [new LiteralType(false), [false], [true, 0, null]],
            'null' => [new NullType(), [null], [0, '', false]],
            'void' => [new VoidType(), [null], [0, '', false]],
            'never' => [new NeverType(), [], [null, 0]],
            'mixed' => [new MixedType(), [null, 0, [], new \stdClass()], []],
            'nullable int' => [UnionType::of(new IntType(), new NullType()), [null, 1], ['1', 1.0]],
            'union of literals' => [UnionType::of(new LiteralType('a'), new LiteralType(1)), ['a', 1], ['b', 2, '1']],
            'list' => [new ListType(new IntType()), [[], [1, 2]], [[1 => 1], ['a' => 1], [1, 'x'], 'x']],
            'non-empty-list' => [new ListType(new IntType(), true), [[1]], [[], [1 => 1]]],
            'array<int|string, mixed>' => [ArrayType::native(), [[], ['a' => null, 3 => [1]]], ['x', null]],
            'array<int, string>' => [new ArrayType(new IntType(), new StringType()), [[], [5 => 'a']], [['a' => 'a'], [1 => 1]]],
            'array<string, int>' => [new ArrayType(new StringType(), new IntType()), [['a' => 1]], [[1], ['1' => 1]]],
            'array<int|string, int>' => [new ArrayType($intOrString, new IntType()), [['a' => 1, 2 => 3]], [['a' => 'b']]],
            'shape' => [
                new ArrayShapeType(['a' => new IntType(), 0 => new StringType()]),
                [['a' => 1, 0 => 'x'], [0 => 'x', 'a' => 1]],
                [['a' => 1], ['a' => 1, 0 => 'x', 1 => 'y'], ['a' => '1', 0 => 'x'], ['a' => 1, 1 => 'x'], 'x'],
            ],
            'empty shape' => [new ArrayShapeType([]), [[]], [[1]]],
            'unit enum' => [new EnumType(Suit::class), [Suit::Hearts], [BackedSuit::Hearts, 'Hearts']],
            'backed enum' => [new EnumType(BackedSuit::class), [BackedSuit::Hearts], [Suit::Hearts, 'H']],
            'class' => [new ClassType(Circle::class), [new Circle()], [new \stdClass(), null]],
            'interface' => [new ClassType(\Countable::class), [new \ArrayObject()], [new \stdClass(), []]],
            'self' => [new SelfType(Circle::class), [new Circle()], [new \stdClass()]],
            'unchecked' => [$unchecked, [], []],
            'list of unchecked' => [new ListType($unchecked), [], [[1 => 1], 'x']],
            'list of int or unchecked' => [new ListType(UnionType::of(new IntType(), $unchecked)), [[1]], [[1 => 1]]],
        ];
        foreach ($table as $name => [$type, $accepted, $rejected]) {
            foreach ($accepted as $i => $value) {
                yield "{$name} accepts #{$i}" => [$type, $value, Certainty::Yes];
            }
            foreach ($rejected as $i => $value) {
                yield "{$name} rejects #{$i}" => [$type, $value, Certainty::No];
            }
        }

        yield 'unchecked is maybe' => [$unchecked, 'anything', Certainty::Maybe];
        yield 'union with unchecked member, checked member matches' => [UnionType::of(new IntType(), $unchecked), 1, Certainty::Yes];
        yield 'union with unchecked member, no checked member matches' => [UnionType::of(new IntType(), $unchecked), 'x', Certainty::Maybe];
        yield 'list of unchecked elements' => [new ListType($unchecked), [1, 2], Certainty::Maybe];
        yield 'array value unchecked' => [new ArrayType(new IntType(), $unchecked), [1 => 1], Certainty::Maybe];
        yield 'array: a rejected key beats an unchecked value' => [new ArrayType(new IntType(), $unchecked), [1 => 1, 'a' => 1], Certainty::No];
        yield 'shape: a rejected item beats an unchecked one' => [
            new ArrayShapeType(['a' => $unchecked, 'b' => new IntType()]),
            ['a' => 1, 'b' => 'x'],
            Certainty::No,
        ];
        yield 'shape with unchecked item' => [new ArrayShapeType(['a' => $unchecked]), ['a' => 1], Certainty::Maybe];
    }

    #[DataProvider('cases')]
    public function testCheck(Type $type, mixed $value, Certainty $expected): void
    {
        self::assertSame($expected, ReturnTypeCheck::check($type, $value, Circle::class));
    }

    /**
     * `static` is the late-bound class; `self` stays the declaring class.
     *
     * @return iterable<string, array{Type, object, class-string, Certainty}>
     */
    public static function lateBound(): iterable
    {
        yield 'static: same class' => [new StaticType(Circle::class), new Circle(), Circle::class, Certainty::Yes];
        yield 'static: the late-bound class, not the declaring one' => [new StaticType(\Exception::class), new \Exception(), \RuntimeException::class, Certainty::No];
        yield 'static: a subclass of the late-bound class' => [new StaticType(\Exception::class), new \DomainException(), \LogicException::class, Certainty::Yes];
        yield 'self: the declaring class even when called late-bound' => [new SelfType(\Exception::class), new \Exception(), \RuntimeException::class, Certainty::Yes];
        yield 'static: unrelated' => [new StaticType(Circle::class), new Square(), Circle::class, Certainty::No];
    }

    /**
     * @param class-string $static
     */
    #[DataProvider('lateBound')]
    public function testStaticIsTheLateBoundClass(Type $type, object $value, string $static, Certainty $expected): void
    {
        self::assertSame($expected, ReturnTypeCheck::check($type, $value, $static));
    }
}
