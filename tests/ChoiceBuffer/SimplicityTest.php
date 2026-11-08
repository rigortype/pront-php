<?php

declare(strict_types=1);

namespace Pront\Tests\ChoiceBuffer;

use PHPUnit\Framework\TestCase;
use Pront\ChoiceBuffer\ChoiceBuffer;
use Pront\ChoiceBuffer\Draw;
use Pront\ChoiceBuffer\DrawKind;
use Pront\ChoiceBuffer\FloatCodec;
use Pront\ChoiceBuffer\Simplicity;

final class SimplicityTest extends TestCase
{
    private static function sign(int $comparison): int
    {
        return $comparison <=> 0;
    }

    /**
     * Asserts that `$ordered` is strictly increasing under `compareDraws`, pairwise and both ways.
     *
     * @param list<Draw> $ordered
     */
    private static function assertStrictlyOrdered(array $ordered): void
    {
        foreach ($ordered as $i => $a) {
            foreach ($ordered as $j => $b) {
                self::assertSame($i <=> $j, self::sign(Simplicity::compareDraws($a, $b)), "draws {$i} vs {$j}");
            }
        }
    }

    public function testShorterBufferIsSimpler(): void
    {
        $short = [new Draw(DrawKind::Int, [9, 1])];
        $long = [new Draw(DrawKind::Int, [0, 0]), new Draw(DrawKind::Int, [0, 0])];
        self::assertSame(-1, self::sign(Simplicity::compareBuffers($short, $long)));
        self::assertSame(1, self::sign(Simplicity::compareBuffers($long, $short)));
        self::assertSame(0, Simplicity::compareBuffers($long, $long));
        self::assertSame(0, Simplicity::compareBuffers([], []));
    }

    public function testEqualLengthComparesDrawByDraw(): void
    {
        $a = [new Draw(DrawKind::Bool, [1]), new Draw(DrawKind::Int, [0, 1])];
        $b = [new Draw(DrawKind::Bool, [1]), new Draw(DrawKind::Int, [2, 0])];
        $c = [new Draw(DrawKind::Bool, [0]), new Draw(DrawKind::Int, [9, 1])];
        self::assertSame(-1, self::sign(Simplicity::compareBuffers($a, $b)), '-1 before 2 by the int rule');
        self::assertSame(-1, self::sign(Simplicity::compareBuffers($c, $a)), 'the first differing draw decides');
    }

    public function testIntDrawsUseTheIntRuleNotSlotOrder(): void
    {
        // 1 = (1, 0) is simpler than -1 = (0, 1), although [0, 1] < [1, 0] lexicographically.
        self::assertStrictlyOrdered([
            new Draw(DrawKind::Int, [0, 0]),
            new Draw(DrawKind::Int, [1, 0]),
            new Draw(DrawKind::Int, [0, 1]),
            new Draw(DrawKind::Int, [2, 0]),
        ]);
    }

    public function testFloatOrdering(): void
    {
        $codec = new FloatCodec();
        $draw = static fn(float $value): Draw => new Draw(DrawKind::Float, $codec->encode($value));
        self::assertStrictlyOrdered([
            $draw(0.0),
            $draw(1.0),
            $draw(-1.0),
            $draw(2.0),
            $draw(1 << 40),
            $draw(0.5),
            $draw(-0.5),
            $draw(0.75),
            $draw(1e300),
            $draw(-0.0),
            $draw(NAN),
            $draw(PHP_FLOAT_MAX),
        ]);
    }

    public function testSingleSlotDrawsAscend(): void
    {
        foreach ([DrawKind::Bool, DrawKind::Nullable, DrawKind::Index, DrawKind::Length, DrawKind::Character, DrawKind::Selector] as $kind) {
            self::assertStrictlyOrdered([new Draw($kind, [0]), new Draw($kind, [1]), new Draw($kind, [7])]);
        }
    }

    public function testDrawsOfDifferentKindsFollowTheCaseOrder(): void
    {
        $cases = DrawKind::cases();
        self::assertStrictlyOrdered(array_map(static fn(DrawKind $kind): Draw => new Draw($kind, [5, 0]), $cases));
        // The kind decides even when the earlier kind's position is larger.
        self::assertSame(-1, self::sign(Simplicity::compareDraws(
            new Draw($cases[0], [9]),
            new Draw($cases[1], [0]),
        )));
    }

    /**
     * Zero-fill: a recording with missing slots is compared through the buffer the replay consumed.
     */
    public function testZeroFilledReplayComparesAsItsConsumedBuffer(): void
    {
        $replay = ChoiceBuffer::replay([]);
        $replay->drawInt(-5, 5);
        $explicit = ChoiceBuffer::replay([[0, 0]]);
        $explicit->drawInt(-5, 5);
        self::assertSame(0, Simplicity::compareBuffers($replay->consumed(), $explicit->consumed()));
    }

    public function testPrefixIsSimplerThanItsExtension(): void
    {
        self::assertSame(-1, self::sign(Simplicity::compareDraws(new Draw(DrawKind::Index, [1]), new Draw(DrawKind::Index, [1, 0]))));
        self::assertSame(1, self::sign(Simplicity::compareDraws(new Draw(DrawKind::Index, [1, 0]), new Draw(DrawKind::Index, [1]))));
    }
}
