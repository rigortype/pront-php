<?php

declare(strict_types=1);

namespace Pront\Tests\ChoiceBuffer;

use PHPUnit\Framework\TestCase;
use Pront\ChoiceBuffer\Prng;

final class PrngTest extends TestCase
{
    public function testSameSeedAndTrialGiveTheSameStream(): void
    {
        $a = Prng::forTrial(42, 7);
        $b = Prng::forTrial(42, 7);
        for ($i = 0; $i < 32; $i++) {
            self::assertSame($a->nextRaw(), $b->nextRaw());
        }
    }

    public function testTrialAndSeedSelectDistinctStreams(): void
    {
        $first = Prng::forTrial(42, 7)->nextRaw();
        self::assertNotSame($first, Prng::forTrial(42, 8)->nextRaw());
        self::assertNotSame($first, Prng::forTrial(43, 7)->nextRaw());
    }

    /**
     * Pins the derivation: a change to it changes replay meaning and must be deliberate.
     */
    public function testDerivationIsPinned(): void
    {
        self::assertSame(
            '9296969696969696',
            bin2hex(pack('P', (new Prng(str_repeat("\x01", 32)))->nextRaw())),
            'raw engine output is little-endian, unchanged',
        );
        $digest = hash('sha256', "pront.trial\0" . pack('P', 1) . pack('P', 0), true);
        self::assertSame((new Prng($digest))->nextRaw(), Prng::forTrial(1, 0)->nextRaw());
        self::assertSame(
            unpack('P', hash('sha256', "pront.target\0" . pack('P', 5) . 'App\\Foo::bar', true))[1],
            Prng::targetSeed(5, 'App\\Foo::bar'),
        );
    }

    public function testIntInRangeStaysInRangeIncludingFullWidth(): void
    {
        $prng = Prng::forTrial(1, 1);
        $ranges = [
            [PHP_INT_MIN, PHP_INT_MAX], [PHP_INT_MIN, -1], [0, PHP_INT_MAX], [-1, PHP_INT_MAX],
            [PHP_INT_MIN, 0], [PHP_INT_MIN, PHP_INT_MIN], [PHP_INT_MAX - 1, PHP_INT_MAX], [-3, 3],
        ];
        $negativeSeen = false;
        foreach ($ranges as [$min, $max]) {
            for ($i = 0; $i < 200; $i++) {
                $value = $prng->intInRange($min, $max);
                self::assertGreaterThanOrEqual($min, $value);
                self::assertLessThanOrEqual($max, $value);
                if ($min === PHP_INT_MIN && $max === PHP_INT_MAX && $value < 0) {
                    $negativeSeen = true;
                }
            }
        }
        self::assertTrue($negativeSeen, 'the full-width range reaches negative values');
    }

    /**
     * Smoke test of uniformity: 6000 draws over 6 values. The chi-square bound 30 (5 degrees of
     * freedom) is far above the 0.001 critical value 20.5, so a fixed seed passes unless the
     * mapping is skewed.
     */
    public function testSmallRangeIsRoughlyUniform(): void
    {
        $prng = Prng::forTrial(2026, 0);
        $counts = array_fill(-2, 6, 0);
        for ($i = 0; $i < 6000; $i++) {
            $counts[$prng->intInRange(-2, 3)]++;
        }
        $chiSquare = 0.0;
        foreach ($counts as $count) {
            $chiSquare += ($count - 1000) ** 2 / 1000;
        }
        self::assertLessThan(30.0, $chiSquare, json_encode($counts) ?: '');
    }

    public function testWrappingArithmetic(): void
    {
        self::assertSame(-1, Prng::wrappingSub(PHP_INT_MAX, PHP_INT_MIN));
        self::assertSame(PHP_INT_MIN, Prng::wrappingAdd(PHP_INT_MAX, 1));
        self::assertSame(PHP_INT_MAX, Prng::wrappingAdd(PHP_INT_MIN, -1));
        self::assertSame(PHP_INT_MAX, Prng::wrappingSub(-1, PHP_INT_MIN));
        self::assertSame(5, Prng::wrappingSub(2, -3));
        self::assertSame(1, Prng::compareUnsigned(-1, PHP_INT_MAX));
        self::assertSame(-1, Prng::compareUnsigned(0, PHP_INT_MIN));
    }

    public function testWeightedAndChanceEdges(): void
    {
        $prng = Prng::forTrial(3, 3);
        for ($i = 0; $i < 100; $i++) {
            self::assertSame(1, $prng->weighted([0, 5, 0]));
            self::assertFalse($prng->chance(0.0));
            self::assertTrue($prng->chance(1.0));
        }
    }
}
