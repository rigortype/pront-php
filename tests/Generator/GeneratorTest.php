<?php

declare(strict_types=1);

namespace Pront\Tests\Generator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pront\ChoiceBuffer\ChoiceBuffer;
use Pront\ChoiceBuffer\DrawKind;
use Pront\ChoiceBuffer\InvalidChoice;
use Pront\ChoiceBuffer\Prng;
use Pront\Generator\EdgeValues;
use Pront\Generator\GeneratorFactory;
use Pront\Generator\Rejected;
use Pront\Tests\Type\Fixtures\BackedSuit;
use Pront\Tests\Type\Fixtures\Suit;
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

final class GeneratorTest extends TestCase
{
    private const int TRIALS = 300;

    /**
     * Every Stage 1 input type (ADR-0014 section 3), with the value the all-zero buffer must give
     * (the simplest value, ADR-0002 section 2).
     *
     * @return iterable<string, array{Type, mixed}>
     */
    public static function types(): iterable
    {
        yield 'int' => [new IntType(), 0];
        yield 'int<5, 10>' => [new IntType(5, 10), 5];
        yield 'int<-9, -3>' => [new IntType(-9, -3), -3];
        yield 'positive-int' => [new IntType(1), 1];
        yield 'negative-int' => [new IntType(PHP_INT_MIN, -1), -1];
        yield 'non-negative-int' => [new IntType(0), 0];
        yield 'int<7, 7>' => [new IntType(7, 7), 7];
        yield 'float' => [new FloatType(), 0.0];
        yield 'bool' => [new BoolType(), false];
        yield 'string' => [new StringType(), ''];
        yield 'non-empty-string' => [new StringType(true), 'a'];
        yield 'literal int' => [new LiteralType(42), 42];
        yield 'literal float' => [new LiteralType(1.5), 1.5];
        yield 'literal string' => [new LiteralType('x'), 'x'];
        yield 'literal false' => [new LiteralType(false), false];
        yield 'literal union' => [UnionType::of(new LiteralType('b'), new LiteralType('a'), new LiteralType(3)), 'b'];
        yield '?int' => [UnionType::of(new IntType(), new NullType()), null];
        yield 'null|string' => [UnionType::of(new NullType(), new StringType()), null];
        yield 'int|string' => [UnionType::of(new IntType(10, 20), new StringType()), 10];
        yield 'string|int|null' => [UnionType::of(new StringType(true), new IntType(), new NullType()), null];
        yield 'unit enum' => [new EnumType(Suit::class), Suit::Hearts];
        yield 'backed enum' => [new EnumType(BackedSuit::class), BackedSuit::Hearts];
        yield '?enum' => [UnionType::of(new EnumType(Suit::class), new NullType()), null];
        yield 'list<int>' => [new ListType(new IntType()), []];
        yield 'non-empty-list<bool>' => [new ListType(new BoolType(), true), [false]];
        yield 'list<list<string>>' => [new ListType(new ListType(new StringType())), []];
        yield 'array<int, float>' => [new ArrayType(new IntType(), new FloatType()), []];
        yield 'array<string, int>' => [new ArrayType(new StringType(), new IntType()), []];
        yield 'array<int|string, bool>' => [new ArrayType(UnionType::of(new IntType(), new StringType()), new BoolType()), []];
        yield 'array<non-negative-int, ?string>' => [
            new ArrayType(new IntType(0), UnionType::of(new StringType(), new NullType())),
            [],
        ];
        yield 'shape' => [
            new ArrayShapeType(['a' => new IntType(), 0 => new StringType(true), 'n' => new ListType(new FloatType())]),
            ['a' => 0, 0 => 'a', 'n' => []],
        ];
        yield 'list of shapes' => [new ListType(new ArrayShapeType(['x' => new BoolType()])), []];
    }

    /**
     * @return iterable<string, array{Type, mixed, float}>
     */
    public static function typesAndRatios(): iterable
    {
        foreach (self::types() as $name => [$type]) {
            foreach ([0.0, 0.1, 1.0] as $ratio) {
                yield "{$name} at ratio {$ratio}" => [$type, $ratio];
            }
        }
    }

    /**
     * Every generated value is in the type; a rejection is allowed only where the type has a
     * rejection rule (a string array key PHP turns into an int).
     */
    #[DataProvider('typesAndRatios')]
    public function testValuesAlwaysSatisfyTheType(Type $type, float $ratio): void
    {
        $generator = (new GeneratorFactory($ratio))->forType($type);
        $rejected = 0;
        for ($k = 0; $k < self::TRIALS; $k++) {
            try {
                $value = $generator->generate(ChoiceBuffer::fresh(Prng::forTrial(1234, $k)));
            } catch (Rejected) {
                $rejected++;
                continue;
            }
            self::assertTrue(
                Membership::holds($type, $value),
                sprintf('trial %d: %s is not in %s', $k, var_export($value, true), $type->describe()),
            );
        }
        if (!str_starts_with($type->describe(), 'array<string,')) {
            self::assertSame(0, $rejected, 'no rejection rule applies to ' . $type->describe());
        }
    }

    /**
     * The same (seed, k) gives the same value and consumed buffer, and replaying the consumed buffer
     * gives them again: the generator draws only through the buffer.
     */
    #[DataProvider('typesAndRatios')]
    public function testDeterministicPerSeedAndTrialAndReplayable(Type $type, float $ratio): void
    {
        $generator = (new GeneratorFactory($ratio))->forType($type);
        for ($k = 0; $k < 100; $k++) {
            $runs = [];
            foreach ([
                ChoiceBuffer::fresh(Prng::forTrial(99, $k)),
                ChoiceBuffer::fresh(Prng::forTrial(99, $k)),
            ] as $buffer) {
                $runs[] = [self::outcome($generator->generate(...), $buffer), $buffer->consumedSlots()];
            }
            self::assertSame($runs[0], $runs[1], "trial {$k} is not deterministic");
            $replay = ChoiceBuffer::replay($runs[0][1]);
            self::assertSame($runs[0][0], self::outcome($generator->generate(...), $replay), "trial {$k} does not replay");
            self::assertSame($runs[0][1], $replay->consumedSlots());
        }
    }

    #[DataProvider('types')]
    public function testTheAllZeroBufferGivesTheSimplestValue(Type $type, mixed $simplest): void
    {
        $buffer = ChoiceBuffer::replay([]);
        $value = (new GeneratorFactory())->forType($type)->generate($buffer);
        self::assertSame(serialize($simplest), serialize($value));
        foreach ($buffer->consumedSlots() as $slots) {
            self::assertSame(array_fill(0, count($slots), 0), $slots);
        }
    }

    public function testIntEdgeValuesAreTheInRangeTableEntriesAndTheEndpoints(): void
    {
        self::assertSame(EdgeValues::INT, EdgeValues::ints(PHP_INT_MIN, PHP_INT_MAX));
        self::assertSame([5, 10], EdgeValues::ints(5, 10));
        self::assertSame([0, 1, 2, PHP_INT_MAX, 2147483647, 2147483648, 4294967295, 4294967296], EdgeValues::ints(0, PHP_INT_MAX));
        self::assertSame([2147483647, 2147483648, 3], EdgeValues::ints(3, 2147483648));
        self::assertSame([-2147483648, -3], EdgeValues::ints(-2147483648, -3));
        self::assertSame([-1, -2147483648], EdgeValues::ints(-2147483648, -1));
        self::assertSame([7], EdgeValues::ints(7, 7));

        self::assertSame([5, 10], self::edgesSeen(new IntType(5, 10)));
        self::assertSame(EdgeValues::INT, self::edgesSeen(new IntType()));
    }

    public function testStringEdgeValues(): void
    {
        $all = EdgeValues::strings(false, false);
        self::assertSame(['', ' ', '0', '00', '1.5', "\n", "\0", str_repeat('a', 1000)], array_slice($all, 0, 8));
        self::assertSame([...EdgeValues::STRING, ...EdgeValues::INVALID_UTF8], array_values(array_diff($all, [str_repeat('a', 1000)])));
        self::assertContains("\xFF", $all);
        self::assertContains("\xE6\x97", $all);
        // ADR-0007: the family emoji is 7 code points and 25 bytes; supplementary kanji take 4 bytes.
        self::assertContains("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}", $all);
        self::assertSame(25, strlen("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}"));
        self::assertSame(4, strlen("\u{20BB7}"));
        self::assertSame(count($all), count(array_unique($all)), 'no edge string is listed twice');
        foreach ($all as $string) {
            if (!in_array($string, EdgeValues::INVALID_UTF8, true)) {
                self::assertSame(1, preg_match('//u', $string), bin2hex($string) . ' is valid UTF-8');
            }
        }

        self::assertNotContains('', EdgeValues::strings(true, false));
        self::assertNotContains('0', EdgeValues::strings(false, true));
        self::assertContains('00', EdgeValues::strings(false, true));
        self::assertSame(count($all) - 1, count(EdgeValues::strings(true, false)));

        self::assertSame($all, self::edgesSeen(new StringType(), 4000));
        self::assertSame(EdgeValues::strings(true, false), self::edgesSeen(new StringType(true), 4000));
    }

    public function testLengthEdgeValues(): void
    {
        self::assertSame([0, 1, 16], EdgeValues::lengths(0, 16));
        self::assertSame([1, 16], EdgeValues::lengths(1, 16));
        self::assertSame([3, 9], EdgeValues::lengths(3, 9));

        $lengths = static fn(array $lists): array => array_values(array_unique(array_map(count(...), $lists)));
        self::assertSame([0, 1, 16], $lengths(self::edgesSeen(new ListType(new IntType()))));
        self::assertSame([1, 16], $lengths(self::edgesSeen(new ListType(new IntType(), true))));
        // An array shares the list's length draw; a repeated key overwrites, so only the bound holds.
        foreach (self::edgesSeen(new ArrayType(new IntType(), new IntType())) as $array) {
            self::assertLessThanOrEqual(16, count($array));
        }
    }

    public function testBoolEdgeValuesAreBoth(): void
    {
        self::assertSame([false, true], self::edgesSeen(new BoolType()));
    }

    /**
     * Fresh, the selector picks an edge with the edge ratio (default 10%); floats follow it through
     * their kind slot.
     */
    public function testEdgeRatio(): void
    {
        $edgeShare = static function (Type $type, float $ratio, \Closure $isEdge): int {
            $generator = (new GeneratorFactory($ratio))->forType($type);
            $edges = 0;
            for ($k = 0; $k < 2000; $k++) {
                $buffer = ChoiceBuffer::fresh(Prng::forTrial(5, $k));
                $generator->generate($buffer);
                $edges += $isEdge($buffer->consumed()[0]->slots) ? 1 : 0;
            }

            return $edges;
        };
        $selector = static fn(array $slots): bool => $slots[0] !== 0;
        $floatEdge = static fn(array $slots): bool => $slots[0] === 2;
        foreach ([new IntType(), new StringType(), new ListType(new BoolType())] as $type) {
            $share = $edgeShare($type, 0.1, $selector);
            self::assertGreaterThan(140, $share, $type->describe());
            self::assertLessThan(260, $share, $type->describe());
            self::assertSame(0, $edgeShare($type, 0.0, $selector));
            self::assertSame(2000, $edgeShare($type, 1.0, $selector));
        }
        $share = $edgeShare(new FloatType(), 0.1, $floatEdge);
        self::assertGreaterThan(140, $share);
        self::assertLessThan(260, $share);
        self::assertSame(0, $edgeShare(new FloatType(), 0.0, $floatEdge));
        self::assertSame(2000, $edgeShare(new FloatType(), 1.0, $floatEdge));
    }

    public function testFloatEdgeValuesAppear(): void
    {
        $generator = (new GeneratorFactory(1.0))->forType(new FloatType());
        $seen = [];
        for ($k = 0; $k < 300; $k++) {
            $seen[var_export($generator->generate(ChoiceBuffer::fresh(Prng::forTrial(6, $k))), true)] = true;
        }
        ksort($seen);
        $expected = array_map(static fn(float $f): string => var_export($f, true), \Pront\ChoiceBuffer\FloatCodec::ADR_0007_EDGES);
        sort($expected);
        self::assertSame($expected, array_keys($seen));
    }

    /**
     * Uniform strings are valid UTF-8, at most 16 characters, and reach beyond ASCII (the character
     * classes of ADR-0002 section 3).
     */
    public function testUniformStrings(): void
    {
        $generator = (new GeneratorFactory(0.0))->forType(new StringType());
        $lengths = [];
        $multiByte = $supplementary = $control = 0;
        for ($k = 0; $k < 2000; $k++) {
            $string = $generator->generate(ChoiceBuffer::fresh(Prng::forTrial(8, $k)));
            self::assertSame(1, preg_match('//u', $string), bin2hex($string));
            $characters = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
            self::assertIsArray($characters);
            $lengths[count($characters)] = true;
            foreach ($characters as $character) {
                $multiByte += strlen($character) > 1 ? 1 : 0;
                $supplementary += strlen($character) === 4 ? 1 : 0;
                $control += preg_match('/\A[\x00-\x1F\x7F]\z/', $character);
            }
        }
        ksort($lengths);
        self::assertSame(range(0, 16), array_keys($lengths));
        self::assertGreaterThan(0, $multiByte);
        self::assertGreaterThan(0, $supplementary);
        self::assertGreaterThan(0, $control);
    }

    /**
     * The characters of a uniform string are the drawn code points, UTF-8 encoded, in order.
     */
    public function testStringCharactersAreTheDrawnCodePoints(): void
    {
        // selector uniform, length 3, then positions 0 ('a'), 52 ('0'), U+65E5 ('日').
        $buffer = ChoiceBuffer::replay([[0], [3], [0], [52], [0x65E5]]);
        self::assertSame('a0日', (new GeneratorFactory())->forType(new StringType())->generate($buffer));
    }

    /**
     * ADR-0007 section 1 / ADR-0014 section 3: a string key never becomes an int key. The edge `"0"`
     * is not offered, and a uniform key such as `"5"` is rejected unless the key type admits 5.
     */
    public function testStringArrayKeys(): void
    {
        $stringKeys = (new GeneratorFactory())->forType(new ArrayType(new StringType(), new IntType()));
        // length 1 (uniform), key: selector uniform, length 1, '5' (position 57); value 0.
        $fiveKeyTail = [[0], [1], [57]];
        $fiveKey = [[0], [1], ...$fiveKeyTail];
        try {
            $stringKeys->generate(ChoiceBuffer::replay($fiveKey));
            self::fail('a key PHP turns into an int was accepted');
        } catch (Rejected $rejected) {
            self::assertStringContainsString("'5'", $rejected->getMessage());
        }
        self::assertSame(['05' => 0], $stringKeys->generate(ChoiceBuffer::replay([[0], [1], [0], [2], [52], [57]])));

        $keyEdges = EdgeValues::strings(false, true);
        foreach ($keyEdges as $index => $edge) {
            // length 1, key selector picks edge $index, value 0.
            $array = $stringKeys->generate(ChoiceBuffer::replay([[0], [1], [$index + 1]]));
            self::assertSame([$edge], array_map(strval(...), array_keys($array)));
            self::assertIsString(array_key_first($array));
        }

        // int|string admits 5, int<0, 3>|string does not.
        $mixed = (new GeneratorFactory())->forType(new ArrayType(UnionType::of(new IntType(), new StringType()), new BoolType()));
        self::assertSame([5 => false], $mixed->generate(ChoiceBuffer::replay([[0], [1], [1], ...$fiveKeyTail])));
        $narrow = (new GeneratorFactory())->forType(new ArrayType(UnionType::of(new IntType(0, 3), new StringType()), new BoolType()));
        $this->expectException(Rejected::class);
        $narrow->generate(ChoiceBuffer::replay([[0], [1], [1], ...$fiveKeyTail]));
    }

    public function testArrayKeyRejectionRateStaysSmall(): void
    {
        $generator = (new GeneratorFactory())->forType(new ArrayType(new StringType(), new IntType()));
        $rejected = 0;
        for ($k = 0; $k < 1000; $k++) {
            try {
                $array = $generator->generate(ChoiceBuffer::fresh(Prng::forTrial(7, $k)));
                foreach (array_keys($array) as $key) {
                    self::assertIsString($key);
                }
            } catch (Rejected) {
                $rejected++;
            }
        }
        self::assertGreaterThan(0, $rejected, 'canonical int string keys do occur and are rejected');
        self::assertLessThan(200, $rejected);
    }

    /**
     * The union draws: the nullable draw first (`null` simplest), then a branch index in declaration
     * order.
     */
    public function testUnionDrawOrder(): void
    {
        $type = UnionType::of(new LiteralType('a'), new NullType(), new LiteralType('b'));
        $generator = (new GeneratorFactory())->forType($type);
        self::assertNull($generator->generate(ChoiceBuffer::replay([[0]])));
        self::assertSame('a', $generator->generate(ChoiceBuffer::replay([[1], [0]])));
        $buffer = ChoiceBuffer::replay([[1], [1]]);
        self::assertSame('b', $generator->generate($buffer));
        self::assertSame([DrawKind::Nullable, DrawKind::Index], array_map(static fn($d) => $d->kind, $buffer->consumed()));

        $single = (new GeneratorFactory())->forType(new LiteralType(3));
        $buffer = ChoiceBuffer::replay([]);
        self::assertSame(3, $single->generate($buffer));
        self::assertSame([], $buffer->consumed(), 'a single value needs no draw');
    }

    public function testEnumCasesInDeclarationOrder(): void
    {
        $generator = (new GeneratorFactory())->forType(new EnumType(BackedSuit::class));
        self::assertSame(BackedSuit::Spades, $generator->generate(ChoiceBuffer::replay([[1]])));
        $this->expectException(InvalidChoice::class);
        $generator->generate(ChoiceBuffer::replay([[2]]));
    }

    /**
     * An out-of-range replayed slot throws; repair is the shrinker's (#19).
     */
    public function testInvalidReplayedSlotsThrow(): void
    {
        $this->expectException(InvalidChoice::class);
        (new GeneratorFactory())->forType(new IntType(5, 10))->generate(ChoiceBuffer::replay([[3]]));
    }

    /**
     * @return list<mixed> the distinct values seen at edge ratio 1, in edge-list order
     */
    private static function edgesSeen(Type $type, int $trials = 1000): array
    {
        $generator = (new GeneratorFactory(1.0))->forType($type);
        $seen = [];
        for ($k = 0; $k < $trials; $k++) {
            $buffer = ChoiceBuffer::fresh(Prng::forTrial(3, $k));
            $value = $generator->generate($buffer);
            $seen[$buffer->consumed()[0]->slots[0]] = $value;
        }
        ksort($seen);
        self::assertSame(range(1, count($seen)), array_keys($seen), 'every edge index is reached');

        return array_values($seen);
    }

    private static function outcome(\Closure $generate, ChoiceBuffer $buffer): string
    {
        try {
            return serialize($generate($buffer));
        } catch (Rejected $rejected) {
            return 'rejected: ' . $rejected->getMessage();
        }
    }
}
