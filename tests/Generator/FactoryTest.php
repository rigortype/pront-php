<?php

declare(strict_types=1);

namespace Pront\Tests\Generator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pront\ChoiceBuffer\ChoiceBuffer;
use Pront\ChoiceBuffer\Prng;
use Pront\Generator\Defaults;
use Pront\Generator\Discards;
use Pront\Generator\GeneratorFactory;
use Pront\Generator\Rejected;
use Pront\Generator\TooManyDiscards;
use Pront\Generator\UngeneratableType;
use Pront\Generator\Utf8;
use Pront\PreRun\PreRunError;
use Pront\Tests\Generator\Fixtures\Erroring;
use Pront\Tests\Generator\Fixtures\Hidden;
use Pront\Tests\Generator\Fixtures\Holder;
use Pront\Tests\Generator\Fixtures\Mixin;
use Pront\Tests\Generator\Fixtures\Asserting;
use Pront\Tests\Generator\Fixtures\Validating;
use Pront\Tests\Generator\Fixtures\Needy;
use Pront\Tests\Generator\Fixtures\Throwing;
use Pront\Tests\Type\Fixtures\Counter;
use Pront\Tests\Type\Fixtures\NoCases;
use Pront\Tests\Type\Fixtures\Shape;
use Pront\Tests\Type\Fixtures\Suit;
use Pront\Type\ArrayType;
use Pront\Type\ClassType;
use Pront\Type\FloatType;
use Pront\Type\IntType;
use Pront\Type\ListType;
use Pront\Type\MixedType;
use Pront\Type\NullType;
use Pront\Type\ResolvedSignature;
use Pront\Type\StringType;
use Pront\Type\Type;
use Pront\Type\TypeResolver;
use Pront\Type\UncheckedType;
use Pront\Type\UnionType;

final class FactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{Type, string}>
     */
    public static function ungeneratable(): iterable
    {
        yield 'mixed' => [new MixedType(), 'mixed cannot be generated'];
        yield 'class' => [new ClassType(\ArrayObject::class), 'until Stage 2'];
        yield 'class in a list' => [new ListType(new ClassType(\ArrayObject::class)), 'until Stage 2'];
        yield 'enum without cases' => [new \Pront\Type\EnumType(NoCases::class), 'has no cases'];
        yield 'native array' => [ArrayType::native(), 'array has no element type'];
        yield 'lone null' => [new NullType(), 'null alone'];
        yield 'unchecked' => [new UncheckedType('callable', 'not in the list'), 'not in the list'];
        yield 'float key' => [new ArrayType(new FloatType(), new IntType()), 'the key type must be'];
        yield 'literal key' => [new ArrayType(UnionType::of(new \Pront\Type\LiteralType('5'), new \Pront\Type\LiteralType('a')), new IntType()), 'the key type must be'];
    }

    #[DataProvider('ungeneratable')]
    public function testUngeneratableTypesAreReportedNotSkipped(Type $type, string $message): void
    {
        $this->expectException(UngeneratableType::class);
        $this->expectExceptionMessage($message);
        (new GeneratorFactory())->forType($type);
    }

    public function testEdgeRatioIsValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GeneratorFactory(1.5);
    }

    public function testDefaults(): void
    {
        self::assertSame(100, Defaults::ITERATIONS);
        self::assertSame(0.1, Defaults::EDGE_RATIO);
        self::assertSame(10, Defaults::DISCARD_FACTOR);
        self::assertSame(16, ChoiceBuffer::DEFAULT_MAX_LENGTH);
    }

    public function testSignatureArgumentsInOrderByName(): void
    {
        $signature = new ResolvedSignature('f()', ['b' => new IntType(3, 3), 'a' => UnionType::of(new StringType(), new NullType())], null, null, []);
        $arguments = (new GeneratorFactory())->forSignature($signature)->generate(ChoiceBuffer::replay([]));
        self::assertSame(['b' => 3, 'a' => null], $arguments);
    }

    public function testSignatureWithPreRunErrorsHasNoGenerator(): void
    {
        $signature = new ResolvedSignature('f()', ['a' => null], null, null, [new PreRunError('f() parameter $a', 'mixed cannot be generated')]);
        $this->expectException(UngeneratableType::class);
        $this->expectExceptionMessage('f() parameter $a: mixed cannot be generated');
        (new GeneratorFactory())->forSignature($signature);
    }

    public function testReceivers(): void
    {
        $factory = new GeneratorFactory();
        $resolver = new TypeResolver();

        self::assertInstanceOf(Counter::class, $factory->forReceiver(new \ReflectionClass(Counter::class), $resolver)->generate(ChoiceBuffer::replay([])));
        self::assertSame(Suit::Spades, $factory->forReceiver(new \ReflectionClass(Suit::class), $resolver)->generate(ChoiceBuffer::replay([[1]])));

        $holders = $factory->forReceiver(new \ReflectionClass(Holder::class), $resolver);
        for ($k = 0; $k < 200; $k++) {
            $holder = $holders->generate(ChoiceBuffer::fresh(Prng::forTrial(2, $k)));
            self::assertInstanceOf(Holder::class, $holder);
            self::assertTrue($holder->digit >= 0 && $holder->digit <= 9);
            self::assertNotSame('', $holder->label);
        }
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function unbuildableReceivers(): iterable
    {
        yield 'private constructor' => [Hidden::class, 'is not public'];
        yield 'interface' => [\Countable::class, 'cannot be instantiated'];
        yield 'abstract class' => [Shape::class, 'cannot be instantiated'];
        yield 'trait' => [Mixin::class, 'cannot be instantiated'];
        yield 'class-typed parameter' => [Needy::class, 'until Stage 2'];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('unbuildableReceivers')]
    public function testUnbuildableReceiversAreReported(string $class, string $message): void
    {
        $this->expectException(UngeneratableType::class);
        $this->expectExceptionMessage($message);
        (new GeneratorFactory())->forReceiver(new \ReflectionClass($class), new TypeResolver());
    }

    public function testConstructorExceptionIsARejectionAndAnErrorPropagates(): void
    {
        $factory = new GeneratorFactory();
        $throwing = $factory->forReceiver(new \ReflectionClass(Throwing::class), new TypeResolver());
        // selector uniform, then n = 1 (magnitude 1, side 0).
        try {
            $throwing->generate(ChoiceBuffer::replay([[0], [1, 0]]));
            self::fail('the constructor exception was not a rejection');
        } catch (Rejected $rejected) {
            self::assertStringContainsString('DomainException: odd 1', $rejected->getMessage());
            self::assertInstanceOf(\DomainException::class, $rejected->getPrevious());
        }
        self::assertSame(2, $throwing->generate(ChoiceBuffer::replay([[0], [2, 0]]))->n);

        foreach ([Asserting::class => 'AssertionError', Validating::class => 'ValueError'] as $class => $error) {
            try {
                $factory->forReceiver(new \ReflectionClass($class), new TypeResolver())->generate(ChoiceBuffer::replay([]));
                self::fail("{$class} was built");
            } catch (Rejected $rejected) {
                self::assertStringContainsString($error, $rejected->getMessage());
            }
        }

        $this->expectException(\TypeError::class);
        $factory->forReceiver(new \ReflectionClass(Erroring::class), new TypeResolver())->generate(ChoiceBuffer::replay([]));
    }

    public function testDiscardThreshold(): void
    {
        $discards = new Discards(2);
        self::assertSame(20, $discards->threshold);
        for ($i = 0; $i < 20; $i++) {
            $discards->record(new Rejected($i < 5 ? 'constructor' : 'key'));
        }
        self::assertSame(20, $discards->count());
        $this->expectException(TooManyDiscards::class);
        $this->expectExceptionMessage('21 candidates were discarded, more than the threshold of 20; most frequent reason (16 times): key');
        $discards->record(new Rejected('key'));
    }

    public function testDiscardsNeedAPositiveIterationCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Discards(0);
    }

    public function testUtf8(): void
    {
        foreach ([
            0x00 => "\x00", 0x41 => 'A', 0x7F => "\x7F", 0x80 => "\xC2\x80", 0x7FF => "\xDF\xBF",
            0x800 => "\xE0\xA0\x80", 0xD7FF => "\xED\x9F\xBF", 0xE000 => "\xEE\x80\x80", 0xFFFF => "\xEF\xBF\xBF",
            0x10000 => "\xF0\x90\x80\x80", 0x20BB7 => "\xF0\xA0\xAE\xB7", 0x10FFFF => "\xF4\x8F\xBF\xBF",
        ] as $codePoint => $bytes) {
            self::assertSame(bin2hex($bytes), bin2hex(Utf8::encode($codePoint)), sprintf('U+%04X', $codePoint));
        }
        foreach ([-1, 0xD800, 0xDFFF, 0x110000] as $invalid) {
            try {
                Utf8::encode($invalid);
                self::fail("encoded {$invalid}");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
