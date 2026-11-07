<?php

declare(strict_types=1);

namespace Pront\Tests\ChoiceBuffer;

use PHPUnit\Framework\TestCase;
use Pront\ChoiceBuffer\ChoiceBuffer;
use Pront\ChoiceBuffer\FloatCodec;
use Pront\ChoiceBuffer\InvalidChoice;
use Pront\ChoiceBuffer\IntOrder;
use Pront\ChoiceBuffer\Prng;

final class FloatCodecTest extends TestCase
{
    public function testEncodingsNamedInTheAdr(): void
    {
        $codec = new FloatCodec();
        self::assertSame([0, 0, 0], $codec->encode(0.0));
        self::assertSame([0, 1, 0], $codec->encode(1.0));
        self::assertSame([0, 0, 1], $codec->encode(-1.0));
        self::assertSame([2, 1], $codec->encode(NAN));
        self::assertSame([2, 0], $codec->encode(-0.0));
        self::assertSame('-0.0', var_export(ChoiceBuffer::replay([[2, 0]])->drawFloat(), true));
        self::assertNan(ChoiceBuffer::replay([[2, 1]])->drawFloat());
    }

    /**
     * Counts the encodings of `$value` by trying every kind's decoder, independently of `encode()`.
     */
    private static function encodingCount(FloatCodec $codec, float $value): int
    {
        $bits = FloatCodec::bits($value);
        $count = 0;
        if (is_finite($value) && floor($value) === $value && abs($value) <= (float) FloatCodec::KIND0_LIMIT) {
            [$magnitude, $side] = IntOrder::encode((int) $value, -FloatCodec::KIND0_LIMIT, FloatCodec::KIND0_LIMIT);
            if (FloatCodec::bits($codec->kindZero($magnitude, $side)) === $bits) {
                $count++;
            }
        }
        try {
            $decoded = $codec->kindOne(($bits >> 32) & 0x7FFFFFFF, $bits & 0xFFFFFFFF, ($bits >> 63) & 1);
            self::assertSame($bits, FloatCodec::bits($decoded));
            $count++;
        } catch (InvalidChoice) {
        }
        foreach ($codec->edges as $index => $edge) {
            if (FloatCodec::bits($codec->kindTwo($index)) === $bits) {
                $count++;
            }
        }

        return $count;
    }

    public function testNoValueHasTwoEncodings(): void
    {
        $codec = new FloatCodec();
        $values = [
            ...FloatCodec::ADR_0007_EDGES,
            0.0, 1.0, -1.0, 16.0, -17.0, 0.5, -0.5, 1e300, -1e-300,
            (float) (1 << 53), (float) -(1 << 53), (float) (1 << 53) + 2.0,
            PHP_FLOAT_MAX / 2, 5e-324,
        ];
        $prng = Prng::forTrial(5, 5);
        for ($i = 0; $i < 2000; $i++) {
            $values[] = ChoiceBuffer::fresh($prng)->drawFloat();
        }
        foreach ($values as $value) {
            self::assertSame(1, self::encodingCount($codec, $value), var_export($value, true));
            $slots = $codec->encode($value);
            self::assertSame(FloatCodec::bits($value), FloatCodec::bits(ChoiceBuffer::replay([$slots])->drawFloat()));
        }
    }

    public function testFreshKindsAndKindZeroRange(): void
    {
        $prng = Prng::forTrial(8, 8);
        $kinds = [0 => 0, 1 => 0, 2 => 0];
        for ($i = 0; $i < 3000; $i++) {
            $buffer = ChoiceBuffer::fresh($prng);
            $value = $buffer->drawFloat();
            $slots = $buffer->consumedSlots()[0];
            $kinds[$slots[0]]++;
            if ($slots[0] === 0) {
                self::assertLessThanOrEqual(16.0, abs($value));
            }
        }
        // Weights 20/70/10 over 3000 draws; wide bounds, a smoke test only.
        self::assertGreaterThan(450, $kinds[0]);
        self::assertGreaterThan(1900, $kinds[1]);
        self::assertGreaterThan(200, $kinds[2]);
    }

    public function testKindZeroReachesBeyondTheFreshRangeOnReplay(): void
    {
        self::assertSame(12345.0, ChoiceBuffer::replay([[0, 12345, 0]])->drawFloat());
        $this->expectException(InvalidChoice::class);
        ChoiceBuffer::replay([[0, (1 << 53) + 1, 0]])->drawFloat();
    }

    public function testEdgeTableValidation(): void
    {
        new FloatCodec([]);
        new FloatCodec([-0.0, 0.5, 1e300]);
        foreach ([[1.0], [0.0], [0.5, 0.5]] as $table) {
            try {
                new FloatCodec($table);
                self::fail('accepted ' . var_export($table, true));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEmptyEdgeTableNeverDrawsKindTwo(): void
    {
        $codec = new FloatCodec([]);
        $prng = Prng::forTrial(4, 4);
        for ($i = 0; $i < 500; $i++) {
            self::assertNotSame(2, $codec->freshSlots($prng)[0]);
        }
    }

    /**
     * Fresh kind 1 values are uniform bit patterns: both signs, tiny and huge exponents.
     */
    public function testFreshKindOneSpreadsOverSignAndExponent(): void
    {
        $codec = new FloatCodec();
        $prng = Prng::forTrial(9, 9);
        $negative = $huge = $tiny = $kindOne = 0;
        while ($kindOne < 1000) {
            $slots = $codec->freshSlots($prng);
            if ($slots[0] !== 1) {
                continue;
            }
            $kindOne++;
            $value = $codec->kindOne($slots[1], $slots[2], $slots[3]);
            $negative += $value < 0 ? 1 : 0;
            $huge += abs($value) > 1e100 ? 1 : 0;
            $tiny += abs($value) < 1e-100 ? 1 : 0;
        }
        // Expected about 500, 334 and 333.
        self::assertGreaterThan(420, $negative);
        self::assertLessThan(580, $negative);
        self::assertGreaterThan(250, $huge);
        self::assertGreaterThan(250, $tiny);
    }
}
