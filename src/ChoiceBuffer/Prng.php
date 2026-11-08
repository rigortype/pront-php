<?php

declare(strict_types=1);

namespace Pront\ChoiceBuffer;

use Random\Engine\Xoshiro256StarStar;

/**
 * Pront's random source: `Xoshiro256StarStar` with Pront's own unbiased range mapping (ADR-0002
 * section 3, ADR-0011 section 1). `Random\Randomizer`, `random_int` and `mt_srand` are never used,
 * so a seed maps to the same values on every PHP version that ships the engine.
 *
 * Seeds are derived by a fixed rule (ADR-0004 item 4): a trial stream is seeded with the SHA-256
 * digest of `"pront.trial\0"`, the 64-bit little-endian target seed and the 64-bit little-endian `k`;
 * a target seed is the first 8 bytes (little-endian) of the SHA-256 digest of `"pront.target\0"`,
 * the 64-bit little-endian run seed and the target name. Changing either rule changes replay meaning.
 */
final class Prng
{
    private readonly Xoshiro256StarStar $engine;

    /**
     * @param string $state 32 bytes of engine state (not all zero)
     */
    public function __construct(string $state)
    {
        if (PHP_INT_SIZE !== 8) {
            throw new \LogicException('Pront requires a 64-bit PHP build (ADR-0011)');
        }
        if (strlen($state) !== 32) {
            throw new \InvalidArgumentException('The engine state must be exactly 32 bytes');
        }
        $this->engine = new Xoshiro256StarStar($state);
    }

    /**
     * The PRNG stream of fresh trial `$k` of a target whose seed is `$seed` (ADR-0004 item 4).
     */
    public static function forTrial(int $seed, int $k): self
    {
        return new self(hash('sha256', "pront.trial\0" . pack('P', $seed) . pack('P', $k), true));
    }

    /**
     * The seed of a target without `#[Verify(seed:)]`, derived from the run seed and the target name
     * (ADR-0004 item 4).
     */
    public static function targetSeed(int $runSeed, string $targetName): int
    {
        $digest = hash('sha256', "pront.target\0" . pack('P', $runSeed) . $targetName, true);
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('P', $digest);

        return $unpacked[1];
    }

    /**
     * The next raw 64-bit engine output, as the PHP `int` with the same bit pattern.
     */
    public function nextRaw(): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('P', $this->engine->generate());

        return $unpacked[1];
    }

    /**
     * A uniformly distributed unsigned value in [0, `$maxInclusive`], where `$maxInclusive` is read as
     * an unsigned 64-bit value (`-1` is 2^64 - 1, the full width, which returns the raw output).
     *
     * Masked rejection sampling: draw, keep the bits of the smallest all-ones mask covering the
     * bound, redraw while above it. No modulo, so no bias; fewer than two draws on average.
     */
    public function uniformUnsigned(int $maxInclusive): int
    {
        $mask = $maxInclusive;
        // Arithmetic shifts: a bound with the top bit set smears to all ones, which is the right mask.
        $mask |= $mask >> 1;
        $mask |= $mask >> 2;
        $mask |= $mask >> 4;
        $mask |= $mask >> 8;
        $mask |= $mask >> 16;
        $mask |= $mask >> 32;
        do {
            $value = $this->nextRaw() & $mask;
        } while (self::compareUnsigned($value, $maxInclusive) > 0);

        return $value;
    }

    /**
     * A uniformly distributed `int` in [`$min`, `$max`]; full-width ranges included, no overflow.
     */
    public function intInRange(int $min, int $max): int
    {
        if ($min > $max) {
            throw new \InvalidArgumentException("Empty range [{$min}, {$max}]");
        }

        return self::wrappingAdd($min, $this->uniformUnsigned(self::wrappingSub($max, $min)));
    }

    /**
     * The index of a bucket chosen with probability proportional to its weight.
     *
     * @param non-empty-list<int<0, max>> $weights
     */
    public function weighted(array $weights): int
    {
        $total = array_sum($weights);
        if ($total <= 0) {
            throw new \InvalidArgumentException('At least one weight must be positive');
        }
        $pick = $this->uniformUnsigned($total - 1);
        foreach ($weights as $index => $weight) {
            if ($pick < $weight) {
                return $index;
            }
            $pick -= $weight;
        }

        throw new \LogicException('unreachable');
    }

    /**
     * True with probability `$ratio` (in [0, 1]), resolved on a 53-bit uniform value.
     */
    public function chance(float $ratio): bool
    {
        if (!($ratio >= 0.0 && $ratio <= 1.0)) {
            throw new \InvalidArgumentException('A ratio must be within [0, 1]');
        }

        return $this->uniformUnsigned((1 << 53) - 1) < $ratio * (float) (1 << 53);
    }

    /**
     * Compares two `int`s as unsigned 64-bit values.
     */
    public static function compareUnsigned(int $a, int $b): int
    {
        return ($a ^ PHP_INT_MIN) <=> ($b ^ PHP_INT_MIN);
    }

    /**
     * `$a + $b` modulo 2^64, as the PHP `int` with that bit pattern (PHP itself overflows to float).
     */
    public static function wrappingAdd(int $a, int $b): int
    {
        if ($b > 0 && $a > PHP_INT_MAX - $b) {
            return ($a + PHP_INT_MIN) + ($b + PHP_INT_MIN);
        }
        if ($b < 0 && $a < PHP_INT_MIN - $b) {
            return ($a - PHP_INT_MIN) + ($b - PHP_INT_MIN);
        }

        return $a + $b;
    }

    /**
     * `$a - $b` modulo 2^64, as the PHP `int` with that bit pattern.
     */
    public static function wrappingSub(int $a, int $b): int
    {
        // -PHP_INT_MIN is PHP_INT_MIN modulo 2^64.
        return self::wrappingAdd($a, $b === PHP_INT_MIN ? PHP_INT_MIN : -$b);
    }
}
