<?php

declare(strict_types=1);

namespace Pront\ChoiceBuffer;

/**
 * The ordering of an `int` draw in [min, max] (ADR-0002 section 2).
 *
 * The origin is 0 when the range contains 0, otherwise the end nearest 0. Values are ordered by
 * distance from the origin, positive before negative at equal distance. A value is recorded as two
 * slots: `magnitude` and `side`. Side 0 holds the distance `d`; side 1 holds `d - 1`, so every
 * slot fits an `int` even for full-width ranges.
 */
final class IntOrder
{
    public static function origin(int $min, int $max): int
    {
        self::assertRange($min, $max);
        if ($min > 0) {
            return $min;
        }

        return $max < 0 ? $max : 0;
    }

    /**
     * @return array{int<0, max>, 0|1} [magnitude, side]
     */
    public static function encode(int $value, int $min, int $max): array
    {
        $origin = self::origin($min, $max);
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException("{$value} is outside [{$min}, {$max}]");
        }
        if ($value >= $origin) {
            // The origin is 0, the minimum (> 0) or the value itself: no overflow.
            /** @var int<0, max> $magnitude */
            $magnitude = $value - $origin;

            return [$magnitude, 0];
        }
        // Here the origin is 0 or max < 0, so $value - $origin >= PHP_INT_MIN + 1.
        /** @var int<0, max> $magnitude */
        $magnitude = -($value - $origin + 1);

        return [$magnitude, 1];
    }

    /**
     * The value at (`$magnitude`, `$side`), or `InvalidChoice` when that position is outside the
     * range.
     */
    public static function decode(int $magnitude, int $side, int $min, int $max): int
    {
        $origin = self::origin($min, $max);
        if ($side !== 0 && $side !== 1) {
            throw new InvalidChoice("int side slot {$side} is not 0 or 1");
        }
        $limit = self::magnitudeLimit($side, $origin, $min, $max);
        if ($magnitude < 0 || $magnitude > $limit) {
            throw new InvalidChoice("int position ({$magnitude}, {$side}) is outside [{$min}, {$max}]");
        }

        return $side === 0 ? $origin + $magnitude : $origin - $magnitude - 1;
    }

    /**
     * The largest magnitude valid on `$side`; -1 when the side holds no value.
     */
    private static function magnitudeLimit(int $side, int $origin, int $min, int $max): int
    {
        if ($side === 0) {
            return $max - $origin;
        }
        if ($origin === $min) {
            return -1;
        }

        // origin is 0 or max (> min); written so that min = PHP_INT_MIN does not overflow.
        return ($origin - 1) - $min;
    }

    private static function assertRange(int $min, int $max): void
    {
        if ($min > $max) {
            throw new \InvalidArgumentException("Empty range [{$min}, {$max}]");
        }
    }
}
