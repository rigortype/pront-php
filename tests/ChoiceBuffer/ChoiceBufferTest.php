<?php

declare(strict_types=1);

namespace Pront\Tests\ChoiceBuffer;

use PHPUnit\Framework\TestCase;
use Pront\ChoiceBuffer\ChoiceBuffer;
use Pront\ChoiceBuffer\Draw;
use Pront\ChoiceBuffer\DrawKind;
use Pront\ChoiceBuffer\FloatCodec;
use Pront\ChoiceBuffer\InvalidChoice;
use Pront\ChoiceBuffer\Prng;

final class ChoiceBufferTest extends TestCase
{
    /**
     * A generator that uses every draw kind, with a dependent draw (the length).
     *
     * @return list<mixed>
     */
    private static function generate(ChoiceBuffer $buffer): array
    {
        $values = [];
        $values[] = $buffer->drawSelector(3, 0.5);
        $values[] = $buffer->drawInt(PHP_INT_MIN, PHP_INT_MAX);
        $values[] = $buffer->drawInt(-4, 4);
        $values[] = $buffer->drawInt(10, 20);
        $values[] = $buffer->drawBool();
        $values[] = $buffer->drawIsNull();
        $values[] = $buffer->drawIndex(5);
        $length = $buffer->drawLength(1);
        $values[] = $length;
        for ($i = 0; $i < $length; $i++) {
            $values[] = $buffer->drawCharacter();
        }
        for ($i = 0; $i < 4; $i++) {
            // var_export keeps NAN and -0.0 distinguishable in assertSame.
            $values[] = var_export($buffer->drawFloat(), true);
        }

        return $values;
    }

    public function testReadingPastTheEndReadsZeros(): void
    {
        $buffer = ChoiceBuffer::replay([]);
        self::assertNull($buffer->drawSelector(3, 0.1));
        self::assertSame(0, $buffer->drawInt(PHP_INT_MIN, PHP_INT_MAX));
        self::assertSame(3, $buffer->drawInt(3, 9));
        self::assertSame(-3, $buffer->drawInt(-9, -3));
        self::assertFalse($buffer->drawBool());
        self::assertTrue($buffer->drawIsNull());
        self::assertSame(0, $buffer->drawIndex(4));
        self::assertSame(1, $buffer->drawLength(1));
        self::assertSame(ord('a'), $buffer->drawCharacter());
        self::assertSame('0.0', var_export($buffer->drawFloat(), true));

        self::assertEquals([
            new Draw(DrawKind::Selector, [0]),
            new Draw(DrawKind::Int, [0, 0]),
            new Draw(DrawKind::Int, [0, 0]),
            new Draw(DrawKind::Int, [0, 0]),
            new Draw(DrawKind::Bool, [0]),
            new Draw(DrawKind::Nullable, [0]),
            new Draw(DrawKind::Index, [0]),
            new Draw(DrawKind::Length, [0]),
            new Draw(DrawKind::Character, [0]),
            new Draw(DrawKind::Float, [0, 0, 0]),
        ], $buffer->consumed(), 'the consumed buffer writes the zero-filled slots out');
    }

    public function testShortDrawIsZeroFilledAndExtraSlotsAreNotConsumed(): void
    {
        $buffer = ChoiceBuffer::replay([[3], [0, 1, 0, 5]]);
        self::assertSame(3, $buffer->drawInt(-10, 10), 'the missing side slot reads as 0');
        self::assertSame(1.0, $buffer->drawFloat());
        self::assertSame([[3, 0], [0, 1, 0]], $buffer->consumedSlots());
    }

    public function testReplayOfTheConsumedBufferReproducesTheValues(): void
    {
        for ($k = 0; $k < 50; $k++) {
            $fresh = ChoiceBuffer::fresh(Prng::forTrial(99, $k));
            $original = self::generate($fresh);

            $replay = ChoiceBuffer::replay($fresh->consumedSlots());
            self::assertSame($original, self::generate($replay), "trial {$k}");
            self::assertEquals($fresh->consumed(), $replay->consumed(), "trial {$k}: consumed buffer");
        }
    }

    public function testFixedSeedIsDeterministic(): void
    {
        $a = ChoiceBuffer::fresh(Prng::forTrial(7, 3));
        $b = ChoiceBuffer::fresh(Prng::forTrial(7, 3));
        self::assertSame(self::generate($a), self::generate($b));
        self::assertSame($a->consumedSlots(), $b->consumedSlots());
    }

    public function testFreshDrawsUseTheWholeRangeOfSmallDraws(): void
    {
        $buffer = ChoiceBuffer::fresh(Prng::forTrial(11, 0));
        $seen = [];
        for ($i = 0; $i < 400; $i++) {
            $seen['bool' . (int) $buffer->drawBool()] = true;
            $seen['null' . (int) $buffer->drawIsNull()] = true;
            $seen['index' . $buffer->drawIndex(3)] = true;
            $seen['length' . $buffer->drawLength(0, 2)] = true;
            $selector = $buffer->drawSelector(2, 0.5);
            $seen['selector' . ($selector ?? 'u')] = true;
        }
        ksort($seen);
        self::assertSame(
            ['bool0', 'bool1', 'index0', 'index1', 'index2', 'length0', 'length1', 'length2', 'null0', 'null1', 'selector0', 'selector1', 'selectoru'],
            array_keys($seen),
        );
    }

    public function testSelectorWithoutEdgesIsUniform(): void
    {
        $buffer = ChoiceBuffer::fresh(Prng::forTrial(1, 1));
        self::assertNull($buffer->drawSelector(0, 1.0));
        self::assertEquals([new Draw(DrawKind::Selector, [0])], $buffer->consumed());
    }

    /**
     * @return iterable<string, array{list<list<int>>, \Closure(ChoiceBuffer): mixed}>
     */
    public static function invalidReplays(): iterable
    {
        yield 'int past max' => [[[5, 0]], static fn(ChoiceBuffer $b) => $b->drawInt(-4, 4)];
        yield 'bool 2' => [[[2]], static fn(ChoiceBuffer $b) => $b->drawBool()];
        yield 'index past count' => [[[3]], static fn(ChoiceBuffer $b) => $b->drawIndex(3)];
        yield 'length past max' => [[[17]], static fn(ChoiceBuffer $b) => $b->drawLength(0)];
        yield 'selector past edges' => [[[3]], static fn(ChoiceBuffer $b) => $b->drawSelector(2, 0.1)];
        yield 'float kind 3' => [[[3]], static fn(ChoiceBuffer $b) => $b->drawFloat()];
        yield 'float kind 1 zero pattern' => [[[1, 0, 0, 0]], static fn(ChoiceBuffer $b) => $b->drawFloat()];
        yield 'float edge past table' => [[[2, 7]], static fn(ChoiceBuffer $b) => $b->drawFloat()];
        yield 'nullable 2' => [[[2]], static fn(ChoiceBuffer $b) => $b->drawIsNull()];
        yield 'length past max with min' => [[[4]], static fn(ChoiceBuffer $b) => $b->drawLength(2, 5)];
        yield 'character past ordering' => [[[0x10F800]], static fn(ChoiceBuffer $b) => $b->drawCharacter()];
    }

    /**
     * @param list<list<int>> $draws
     * @param \Closure(ChoiceBuffer): mixed $draw
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidReplays')]
    public function testInvalidPositionsAreRejectedNotClamped(array $draws, \Closure $draw): void
    {
        $this->expectException(InvalidChoice::class);
        $draw(ChoiceBuffer::replay($draws));
    }

    /**
     * The invalid replays above, each recorded with the kind it is read as: a repairing buffer
     * clamps them to the nearest valid position.
     *
     * @return iterable<string, array{list<Draw>, \Closure(ChoiceBuffer): mixed, mixed, list<int>}>
     */
    public static function repairs(): iterable
    {
        yield 'int past max' => [[new Draw(DrawKind::Int, [5, 0])], static fn(ChoiceBuffer $b) => $b->drawInt(-4, 4), 4, [4, 0]];
        yield 'int empty side' => [[new Draw(DrawKind::Int, [2, 1])], static fn(ChoiceBuffer $b) => $b->drawInt(0, 9), 3, [3, 0]];
        yield 'bool 2' => [[new Draw(DrawKind::Bool, [2])], static fn(ChoiceBuffer $b) => $b->drawBool(), true, [1]];
        yield 'index past count' => [[new Draw(DrawKind::Index, [3])], static fn(ChoiceBuffer $b) => $b->drawIndex(3), 2, [2]];
        yield 'length past max' => [[new Draw(DrawKind::Length, [17])], static fn(ChoiceBuffer $b) => $b->drawLength(0), 16, [16]];
        yield 'length past max with min' => [[new Draw(DrawKind::Length, [4])], static fn(ChoiceBuffer $b) => $b->drawLength(2, 5), 5, [3]];
        yield 'selector past edges' => [[new Draw(DrawKind::Selector, [3])], static fn(ChoiceBuffer $b) => $b->drawSelector(2, 0.1), 1, [2]];
        yield 'nullable 2' => [[new Draw(DrawKind::Nullable, [2])], static fn(ChoiceBuffer $b) => $b->drawIsNull(), false, [1]];
        yield 'character past ordering' => [
            [new Draw(DrawKind::Character, [0x10F800])],
            static fn(ChoiceBuffer $b) => $b->drawCharacter(),
            \Pront\ChoiceBuffer\Characters::codePointAt(0x10F7FF),
            [0x10F7FF],
        ];
        yield 'float kind 3' => [[new Draw(DrawKind::Float, [3, 1])], static fn(ChoiceBuffer $b) => var_export($b->drawFloat(), true), 'NAN', [2, 1]];
        yield 'float edge past table' => [[new Draw(DrawKind::Float, [2, 7])], static fn(ChoiceBuffer $b) => $b->drawFloat(), PHP_FLOAT_MAX, [2, 6]];
        yield 'float kind 0 past range' => [
            [new Draw(DrawKind::Float, [0, FloatCodec::KIND0_LIMIT + 5, 0])],
            static fn(ChoiceBuffer $b) => $b->drawFloat(),
            (float) FloatCodec::KIND0_LIMIT,
            [0, FloatCodec::KIND0_LIMIT, 0],
        ];
        yield 'float kind 1 sign 2' => [
            [new Draw(DrawKind::Float, [1, 0x40091EB8, 0x51EB851F, 2])],
            static fn(ChoiceBuffer $b) => $b->drawFloat(),
            -3.14,
            [1, 0x40091EB8, 0x51EB851F, 1],
        ];
    }

    /**
     * @param list<Draw> $draws
     * @param \Closure(ChoiceBuffer): mixed $draw
     * @param list<int> $slots
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('repairs')]
    public function testRepairingClampsDrawsOfTheRecordedKind(array $draws, \Closure $draw, mixed $expected, array $slots): void
    {
        $buffer = ChoiceBuffer::repairing($draws);
        self::assertSame($expected, $draw($buffer));
        self::assertTrue($buffer->repaired());
        self::assertSame([$slots], $buffer->consumedSlots());
        self::assertSame($draws[0]->kind, $buffer->consumed()[0]->kind);
    }

    /**
     * The repair rows recorded under another kind (as when an edit shifted the draw there): every
     * other kind is tried, and none is clamped.
     *
     * @param list<Draw> $draws
     * @param \Closure(ChoiceBuffer): mixed $draw
     * @param mixed $expected the repaired value of the row, not reached here
     * @param list<int> $slots the repaired slots of the row, not reached here
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('repairs')]
    public function testRepairingDoesNotClampDrawsOfAnotherKind(array $draws, \Closure $draw, mixed $expected, array $slots): void
    {
        foreach (DrawKind::cases() as $kind) {
            if ($kind === $draws[0]->kind) {
                continue;
            }
            try {
                $draw(ChoiceBuffer::repairing([new Draw($kind, $draws[0]->slots)]));
                self::fail("a slot recorded as {$kind->name} was clamped");
            } catch (InvalidChoice) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRepairingCannotFixAKindOnePatternWithoutEncoding(): void
    {
        $this->expectException(InvalidChoice::class);
        ChoiceBuffer::repairing([new Draw(DrawKind::Float, [1, 0, 0, 0])])->drawFloat();
    }

    public function testRepairingAValidRecordingIsAReplay(): void
    {
        $fresh = ChoiceBuffer::fresh(Prng::forTrial(7, 3));
        $values = self::generate($fresh);
        $repairing = ChoiceBuffer::repairing($fresh->consumed());

        self::assertSame($values, self::generate($repairing));
        self::assertFalse($repairing->repaired());
        self::assertEquals($fresh->consumed(), $repairing->consumed());
    }

    public function testMalformedRecordingsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ChoiceBuffer::replay([[0, -1]]);
    }

    public function testInjectedEdgeTable(): void
    {
        $floats = new FloatCodec([0.5]);
        self::assertSame(0.5, ChoiceBuffer::replay([[2, 0]], $floats)->drawFloat());
    }

    public function testSelectorFollowsTheEdgeRatio(): void
    {
        $count = static function (float $ratio): array {
            $buffer = ChoiceBuffer::fresh(Prng::forTrial(21, (int) ($ratio * 100)));
            $hits = [0, 0, 0];
            $edges = 0;
            for ($i = 0; $i < 5000; $i++) {
                $selected = $buffer->drawSelector(3, $ratio);
                if ($selected !== null) {
                    $edges++;
                    $hits[$selected]++;
                }
            }

            return [$edges, $hits];
        };
        self::assertSame(0, $count(0.0)[0]);
        [$all, $hits] = $count(1.0);
        self::assertSame(5000, $all);
        foreach ($hits as $hit) {
            self::assertGreaterThan(1500, $hit, 'edge candidates are uniform');
        }
        $tenth = $count(0.1)[0];
        self::assertGreaterThan(400, $tenth);
        self::assertLessThan(600, $tenth);
    }

    public function testLengthRangeUpToIntMaxDoesNotOverflow(): void
    {
        self::assertSame(0, ChoiceBuffer::replay([])->drawLength(0, PHP_INT_MAX));
        self::assertSame(PHP_INT_MAX, ChoiceBuffer::replay([[PHP_INT_MAX]])->drawLength(0, PHP_INT_MAX));
        $value = ChoiceBuffer::fresh(Prng::forTrial(1, 2))->drawLength(0, PHP_INT_MAX);
        self::assertGreaterThanOrEqual(0, $value);
    }

    public function testLengthSlotIsOffsetByTheMinimum(): void
    {
        self::assertSame(5, ChoiceBuffer::replay([[3]])->drawLength(2, 5));
        $buffer = ChoiceBuffer::fresh(Prng::forTrial(30, 0));
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[$buffer->drawLength(2, 5)] = true;
        }
        ksort($seen);
        self::assertSame([2, 3, 4, 5], array_keys($seen));
    }

    /**
     * With no stated maximum the maximum is 16, or the minimum when that is larger, so a type with a
     * larger minimum length stays generatable.
     */
    public function testDefaultMaximumIsRaisedToTheMinimum(): void
    {
        self::assertSame(17, ChoiceBuffer::replay([])->drawLength(17));
        self::assertSame(16, ChoiceBuffer::replay([[16]])->drawLength(0));
        $this->expectException(InvalidChoice::class);
        ChoiceBuffer::replay([[1]])->drawLength(17);
    }

    /**
     * A kind 1 payload that is invalid only because slots were read past the end decodes as the
     * all-zero draw (ADR-0002 section 4: reading past the end is not a failure of the candidate).
     *
     * @return iterable<string, array{list<list<int>>}>
     */
    public static function zeroFilledKindOne(): iterable
    {
        yield 'whole payload missing' => [[[1]]];
        yield 'low and sign missing, 1.0' => [[[1, 0x3FF00000]]];
        yield 'sign missing, -0.0 is not it but 0.0' => [[[1, 0, 0]]];
    }

    /**
     * @param list<list<int>> $draws
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('zeroFilledKindOne')]
    public function testZeroFilledInvalidKindOneIsTheAllZeroDraw(array $draws): void
    {
        $buffer = ChoiceBuffer::replay($draws);
        self::assertSame('0.0', var_export($buffer->drawFloat(), true));
        self::assertSame([[0, 0, 0]], $buffer->consumedSlots());
        self::assertSame('0.0', var_export(ChoiceBuffer::replay($buffer->consumedSlots())->drawFloat(), true));
    }

    public function testZeroFilledValidKindOneKeepsItsPattern(): void
    {
        $buffer = ChoiceBuffer::replay([[1, 0x3FF00001]]);
        self::assertSame(FloatCodec::fromBits(0x3FF0000100000000), $buffer->drawFloat());
        self::assertSame([[1, 0x3FF00001, 0, 0]], $buffer->consumedSlots());
    }

    public function testZeroFilledOutOfRangeKindOneStillThrows(): void
    {
        $this->expectException(InvalidChoice::class);
        ChoiceBuffer::replay([[1, 0x80000000]])->drawFloat();
    }

    public function testFloatKindTwoFollowsTheEdgeRatio(): void
    {
        $kindTwo = static function (float $ratio): int {
            $buffer = ChoiceBuffer::fresh(Prng::forTrial(40, 0));
            $count = 0;
            for ($i = 0; $i < 4000; $i++) {
                $buffer->drawFloat($ratio);
                $count += $buffer->consumed()[$i]->slots[0] === 2 ? 1 : 0;
            }

            return $count;
        };
        self::assertSame(0, $kindTwo(0.0));
        self::assertSame(4000, $kindTwo(1.0));
        self::assertGreaterThan(300, $kindTwo(0.1));
        self::assertLessThan(500, $kindTwo(0.1));
        $this->expectException(\InvalidArgumentException::class);
        ChoiceBuffer::fresh(Prng::forTrial(40, 1))->drawFloat(-0.1);
    }

    public function testBoolAndNullableAreEvenFresh(): void
    {
        $buffer = ChoiceBuffer::fresh(Prng::forTrial(31, 0));
        $true = $null = 0;
        for ($i = 0; $i < 4000; $i++) {
            $true += $buffer->drawBool() ? 1 : 0;
            $null += $buffer->drawIsNull() ? 1 : 0;
        }
        // Expected 2000 each; the bounds are about 4.4 standard deviations wide.
        self::assertGreaterThan(1860, $true);
        self::assertLessThan(2140, $true);
        self::assertGreaterThan(1860, $null);
        self::assertLessThan(2140, $null);
    }
}
