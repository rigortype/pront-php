<?php

declare(strict_types=1);

namespace Pront\Tests\Type;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pront\Type\ArrayShapeType;
use Pront\Type\ArrayType;
use Pront\Type\BoolType;
use Pront\Type\Certainty;
use Pront\Type\IntegerLiteral;
use Pront\Type\IntType;
use Pront\Type\ListType;
use Pront\Type\LiteralType;
use Pront\Type\MixedType;
use Pront\Type\NameScope;
use Pront\Type\NullType;
use Pront\Type\StringType;
use Pront\Type\Subtype;
use Pront\Type\Type;
use Pront\Type\UncheckedType;
use Pront\Type\UnionType;

/**
 * The value objects, the integer-literal parser, name resolution rules and the subtype relation on
 * the parts native types never reach.
 */
final class ModelTest extends TestCase
{
    /**
     * @return array<string, array{string, ?int}>
     */
    public static function integerLiterals(): array
    {
        return [
            'zero' => ['0', 0],
            'decimal' => ['42', 42],
            'negative' => ['-42', -42],
            'plus' => ['+7', 7],
            'separator' => ['1_000', 1000],
            'hex' => ['0x1F', 31],
            'hex upper' => ['0X1f', 31],
            'binary' => ['0b101', 5],
            'octal prefix' => ['0o17', 15],
            'legacy octal' => ['017', 15],
            'max' => ['9223372036854775807', PHP_INT_MAX],
            'min' => ['-9223372036854775808', PHP_INT_MIN],
            'max hex' => ['0x7FFFFFFFFFFFFFFF', PHP_INT_MAX],
            'above max' => ['9223372036854775808', null],
            'below min' => ['-9223372036854775809', null],
            'hex above max' => ['0x8000000000000000', null],
            'bad octal digit' => ['09', null],
            'bad binary digit' => ['0b2', null],
            'empty hex' => ['0x', null],
        ];
    }

    #[DataProvider('integerLiterals')]
    public function testIntegerLiteral(string $literal, ?int $expected): void
    {
        self::assertSame($expected, IntegerLiteral::parse($literal));
    }

    public function testUnionFlattensAndDeduplicates(): void
    {
        $union = UnionType::of(new IntType(), UnionType::of(new StringType(), new IntType()), new NullType());

        self::assertSame('int|string|null', $union->describe());
        self::assertSame('int', UnionType::of(new IntType(), new IntType())->describe());
    }

    /**
     * @return array<string, array{Type, string}>
     */
    public static function descriptions(): array
    {
        return [
            'string literal escapes' => [new LiteralType("it's \\"), "'it\\'s \\\\'"],
            'float literal keeps the point' => [new LiteralType(1.0), '1.0'],
            'shape key quoting' => [
                new ArrayShapeType(['a' => new IntType(), 'b c' => new BoolType(), 3 => new NullType()]),
                "array{a: int, 'b c': bool, 3: null}",
            ],
            'native array' => [ArrayType::native(), 'array'],
            'array of mixed with int keys' => [new ArrayType(new IntType(), new MixedType()), 'array<int, mixed>'],
            'unchecked' => [new UncheckedType('callable', 'not in the Stage 1 list'), 'unchecked(callable)'],
        ];
    }

    #[DataProvider('descriptions')]
    public function testDescribe(Type $type, string $expected): void
    {
        self::assertSame($expected, $type->describe());
    }

    public function testEmptyIntRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new IntType(2, 1);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function classNames(): array
    {
        return [
            'imported' => ['Foo', 'Lib\Foo'],
            'imported case-insensitively' => ['foo', 'Lib\Foo'],
            'imported prefix' => ['Sub\Bar', 'Lib\Sub\Bar'],
            'aliased' => ['Alias', 'Other\Thing'],
            'not imported' => ['Baz', 'App\Baz'],
            'fully qualified' => ['\Baz', 'Baz'],
            'namespace-relative' => ['namespace\Baz', 'App\Baz'],
            'namespace-relative ignores imports' => ['namespace\Foo', 'App\Foo'],
        ];
    }

    #[DataProvider('classNames')]
    public function testNameScopeResolvesClassNames(string $written, string $expected): void
    {
        $scope = new NameScope('App', ['foo' => 'Lib\Foo', 'sub' => 'Lib\Sub', 'alias' => 'Other\Thing']);

        self::assertSame($expected, $scope->resolveClassName($written));
    }

    public function testGlobalNamespaceScope(): void
    {
        self::assertSame('Baz', (new NameScope())->resolveClassName('Baz'));
    }

    /**
     * Relations a native supertype never exercises: list, shape and keyed-array supertypes.
     *
     * @return array<string, array{Type, Type, Certainty}>
     */
    public static function subtypes(): array
    {
        $int = new IntType();
        $string = new StringType();

        return [
            'non-empty list in list' => [new ListType($int, true), new ListType($int), Certainty::Yes],
            'list in non-empty list' => [new ListType($int), new ListType($int, true), Certainty::No],
            'list covariant' => [new ListType(new IntType(0, 5)), new ListType($int), Certainty::Yes],
            'list shape in list' => [new ArrayShapeType([$int, new IntType(1)]), new ListType($int, true), Certainty::Yes],
            'keyed shape in list' => [new ArrayShapeType([1 => $int]), new ListType($int), Certainty::No],
            'empty shape in non-empty list' => [new ArrayShapeType([]), new ListType($int, true), Certainty::No],
            'list in int-keyed array' => [new ListType($int), new ArrayType($int, $int), Certainty::Yes],
            'list in string-keyed array' => [new ListType($int), new ArrayType($string, $int), Certainty::No],
            'shape keys in string-keyed array' => [new ArrayShapeType(['a' => $int]), new ArrayType($string, $int), Certainty::Yes],
            'shape int key in string-keyed array' => [new ArrayShapeType([0 => $int]), new ArrayType($string, $int), Certainty::No],
            'shape in same shape, other order' => [
                new ArrayShapeType(['b' => $string, 'a' => new IntType(0)]),
                new ArrayShapeType(['a' => $int, 'b' => $string]),
                Certainty::Yes,
            ],
            'shape missing a key' => [new ArrayShapeType(['a' => $int]), new ArrayShapeType(['b' => $int]), Certainty::No],
            'bool in literal union' => [new BoolType(), UnionType::of(new LiteralType(true), new LiteralType(false)), Certainty::Yes],
            'range in wider range' => [new IntType(1, 5), new IntType(0, 10), Certainty::Yes],
            'range overlapping' => [new IntType(-1, 5), new IntType(0, 10), Certainty::No],
            'empty literal in non-empty-string' => [new LiteralType(''), new StringType(true), Certainty::No],
            'string in non-empty-string' => [$string, new StringType(true), Certainty::No],
            'unchecked in int' => [new UncheckedType('numeric', ''), $int, Certainty::Maybe],
            'unchecked in mixed' => [new UncheckedType('numeric', ''), new MixedType(), Certainty::Yes],
            'int in int or unchecked' => [$int, UnionType::of($int, new UncheckedType('object', '')), Certainty::Yes],
            'string in int or unchecked' => [$string, UnionType::of($int, new UncheckedType('object', '')), Certainty::Maybe],
        ];
    }

    #[DataProvider('subtypes')]
    public function testSubtype(Type $sub, Type $super, Certainty $expected): void
    {
        self::assertSame($expected, Subtype::check($sub, $super));
    }
}
