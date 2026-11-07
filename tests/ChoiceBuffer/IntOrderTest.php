<?php

declare(strict_types=1);

namespace Pront\Tests\ChoiceBuffer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pront\ChoiceBuffer\IntOrder;
use Pront\ChoiceBuffer\InvalidChoice;
use Pront\ChoiceBuffer\Simplicity;

final class IntOrderTest extends TestCase
{
    /**
     * The test vector of ADR-0002 section 2.
     */
    public function testAdrVectorOverMinusFourToFour(): void
    {
        $expected = [
            0 => [0, 0], 1 => [1, 0], -1 => [0, 1], 2 => [2, 0], -2 => [1, 1],
            3 => [3, 0], -3 => [2, 1], 4 => [4, 0], -4 => [3, 1],
        ];
        foreach ($expected as $value => $slots) {
            self::assertSame($slots, IntOrder::encode($value, -4, 4), "encode({$value})");
            self::assertSame($value, IntOrder::decode($slots[0], $slots[1], -4, 4), "decode({$value})");
        }

        $values = range(-4, 4);
        shuffle($values);
        usort($values, static function (int $a, int $b): int {
            [$mA, $sA] = IntOrder::encode($a, -4, 4);
            [$mB, $sB] = IntOrder::encode($b, -4, 4);

            return Simplicity::compareInt($mA, $sA, $mB, $sB);
        });
        self::assertSame([0, 1, -1, 2, -2, 3, -3, 4, -4], $values);
    }

    /**
     * The comparator is a total order over the full `int` range, extremes included, and agrees with
     * "distance from 0 ascending, positive first".
     */
    public function testComparatorIsTotalOverFullWidth(): void
    {
        $ordered = [
            0, 1, -1, 2, -2, 3,
            PHP_INT_MAX - 1, -(PHP_INT_MAX - 1), PHP_INT_MAX, -PHP_INT_MAX, PHP_INT_MIN,
        ];
        $positions = array_map(
            static fn(int $value): array => IntOrder::encode($value, PHP_INT_MIN, PHP_INT_MAX),
            $ordered,
        );
        self::assertSame([PHP_INT_MAX, 0], $positions[8]);
        self::assertSame([PHP_INT_MAX, 1], $positions[10]);

        $compare = static fn(array $a, array $b): int => Simplicity::compareInt($a[0], $a[1], $b[0], $b[1]);
        $count = count($positions);
        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $count; $j++) {
                $c = $compare($positions[$i], $positions[$j]);
                self::assertSame($i <=> $j, $c <=> 0, "compare({$ordered[$i]}, {$ordered[$j]})");
                self::assertSame(-($c <=> 0), $compare($positions[$j], $positions[$i]) <=> 0, 'antisymmetry');
            }
        }
    }

    /**
     * @return iterable<string, array{int, int, list<int>}>
     */
    public static function ranges(): iterable
    {
        yield 'full width' => [PHP_INT_MIN, PHP_INT_MAX, [PHP_INT_MIN, PHP_INT_MIN + 1, -1, 0, 1, PHP_INT_MAX]];
        yield 'positive' => [3, 7, [3, 4, 5, 6, 7]];
        yield 'negative' => [-7, -3, [-7, -6, -5, -4, -3]];
        yield 'skewed' => [-1, 4, [-1, 0, 1, 2, 3, 4]];
        yield 'single minimum' => [PHP_INT_MIN, PHP_INT_MIN, [PHP_INT_MIN]];
        yield 'single maximum' => [PHP_INT_MAX, PHP_INT_MAX, [PHP_INT_MAX]];
        yield 'negative to minimum' => [PHP_INT_MIN, -1, [PHP_INT_MIN, -2, -1]];
        yield 'positive to maximum' => [1, PHP_INT_MAX, [1, 2, PHP_INT_MAX]];
    }

    /**
     * @param list<int> $values
     */
    #[DataProvider('ranges')]
    public function testRoundTripAndOrigin(int $min, int $max, array $values): void
    {
        foreach ($values as $value) {
            [$magnitude, $side] = IntOrder::encode($value, $min, $max);
            self::assertSame($value, IntOrder::decode($magnitude, $side, $min, $max));
        }
        self::assertSame(IntOrder::origin($min, $max), IntOrder::decode(0, 0, $min, $max), 'all-zero is the origin');
    }

    public function testOriginRule(): void
    {
        self::assertSame(0, IntOrder::origin(-5, 5));
        self::assertSame(3, IntOrder::origin(3, 9));
        self::assertSame(-3, IntOrder::origin(-9, -3));
    }

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function outOfRange(): iterable
    {
        yield 'past max' => [5, 0, -4, 4];
        yield 'past min' => [4, 1, -4, 4];
        yield 'no negative side' => [0, 1, 3, 7];
        yield 'no positive side' => [1, 0, -7, -3];
        yield 'bad side' => [0, 2, -4, 4];
        yield 'single value' => [0, 1, PHP_INT_MIN, PHP_INT_MIN];
    }

    #[DataProvider('outOfRange')]
    public function testDecodeRejectsPositionsOutsideTheRange(int $magnitude, int $side, int $min, int $max): void
    {
        $this->expectException(InvalidChoice::class);
        IntOrder::decode($magnitude, $side, $min, $max);
    }
}
