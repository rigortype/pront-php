<?php

declare(strict_types=1);

namespace Pront\ChoiceBuffer;

/**
 * The character draw (ADR-0002 sections 2 and 3): the ordering of Unicode scalar values and the
 * weighted classes fresh characters come from.
 *
 * Ordering: `a`-`z`, `A`-`Z`, `0`-`9`, space, the 32 ASCII punctuation characters by code point,
 * then every remaining Unicode scalar value by code point. Surrogates are not scalar values and
 * have no position. The slot is the position; `a` is position 0.
 *
 * Fresh draws use four disjoint classes, uniform within a class. The combining table takes
 * precedence over the other classes and noncharacters are in no class. The tables are fixed data;
 * `IntlChar` is not consulted, so the mapping is the same with or without `ext-intl`.
 */
final class Characters
{
    /** The 95 printable ASCII characters in ordering position order. */
    public const string ASCII_ORDER = 'abcdefghijklmnopqrstuvwxyz'
        . 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'
        . '0123456789'
        . ' '
        . '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    /** Number of positions: every Unicode scalar value (U+0000-U+10FFFF without surrogates). */
    public const int POSITION_COUNT = 0x110000 - 0x800;

    /** The fixed combining table (ADR-0002 section 3), inclusive ranges. */
    public const array COMBINING = [
        [0x0300, 0x036F],
        [0x200D, 0x200D],
        [0x3099, 0x309A],
        [0xFE00, 0xFE0F],
        [0xE0100, 0xE01EF],
    ];

    /** Class weights in percent: printable ASCII, rest of the BMP, supplementary, control+combining. */
    public const array WEIGHTS = [70, 15, 10, 5];

    /** @var list<list<array{int, int}>>|null */
    private static ?array $classes = null;

    /**
     * The code point at ordering position `$position`.
     */
    public static function codePointAt(int $position): int
    {
        if ($position < 0 || $position >= self::POSITION_COUNT) {
            throw new InvalidChoice("character position {$position} is outside the ordering");
        }
        if ($position < 95) {
            return ord(self::ASCII_ORDER[$position]);
        }
        if ($position < 127) {
            return $position - 95;
        }
        if ($position === 127) {
            return 0x7F;
        }
        // From position 128 (U+0080) the positions equal the code points up to the surrogates.
        return $position < 0xD800 ? $position : $position + 0x800;
    }

    /**
     * The ordering position of the Unicode scalar value `$codePoint`.
     */
    public static function positionOf(int $codePoint): int
    {
        if ($codePoint < 0 || $codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)) {
            throw new \InvalidArgumentException(sprintf('U+%04X is not a Unicode scalar value', $codePoint));
        }
        if ($codePoint >= 0x20 && $codePoint <= 0x7E) {
            $position = strpos(self::ASCII_ORDER, chr($codePoint));
            \assert($position !== false);

            return $position;
        }
        if ($codePoint < 0x20) {
            return 95 + $codePoint;
        }
        if ($codePoint === 0x7F) {
            return 127;
        }

        return $codePoint < 0xD800 ? $codePoint : $codePoint - 0x800;
    }

    /**
     * A fresh code point: a class by weight, then uniform within the class.
     */
    public static function fresh(Prng $prng): int
    {
        $ranges = self::classes()[$prng->weighted(self::WEIGHTS)];
        $index = $prng->uniformUnsigned(self::size($ranges) - 1);
        foreach ($ranges as [$low, $high]) {
            $width = $high - $low + 1;
            if ($index < $width) {
                return $low + $index;
            }
            $index -= $width;
        }

        throw new \LogicException('unreachable');
    }

    /**
     * The four fresh-draw classes in `WEIGHTS` order, each as sorted, disjoint inclusive ranges.
     *
     * @return list<list<array{int, int}>>
     */
    public static function classes(): array
    {
        if (self::$classes !== null) {
            return self::$classes;
        }
        $noncharacters = self::noncharacters();
        $excluded = self::normalize([...self::COMBINING, ...$noncharacters]);

        return self::$classes = [
            self::subtract([[0x20, 0x7E]], $excluded),
            self::subtract([[0xA0, 0xFFFF]], self::normalize([[0xD800, 0xDFFF], ...$excluded])),
            self::subtract([[0x10000, 0x10FFFF]], $excluded),
            self::subtract(self::normalize([[0x00, 0x1F], [0x7F, 0x9F], ...self::COMBINING]), $noncharacters),
        ];
    }

    /**
     * The 66 Unicode noncharacters: U+FDD0-U+FDEF and the last two code points of every plane.
     *
     * @return list<array{int, int}>
     */
    public static function noncharacters(): array
    {
        $ranges = [[0xFDD0, 0xFDEF]];
        for ($plane = 0; $plane <= 0x10; $plane++) {
            $ranges[] = [$plane * 0x10000 + 0xFFFE, $plane * 0x10000 + 0xFFFF];
        }

        return self::normalize($ranges);
    }

    /**
     * @param list<array{int, int}> $ranges
     */
    public static function size(array $ranges): int
    {
        $size = 0;
        foreach ($ranges as [$low, $high]) {
            $size += $high - $low + 1;
        }

        return $size;
    }

    /**
     * Sorted, merged ranges.
     *
     * @param list<array{int, int}> $ranges
     * @return list<array{int, int}>
     */
    private static function normalize(array $ranges): array
    {
        usort($ranges, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($ranges as [$low, $high]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $low <= $merged[$last][1] + 1) {
                $merged[$last][1] = max($merged[$last][1], $high);
            } else {
                $merged[] = [$low, $high];
            }
        }

        return $merged;
    }

    /**
     * `$ranges` without the code points in `$excluded` (both normalized).
     *
     * @param list<array{int, int}> $ranges
     * @param list<array{int, int}> $excluded
     * @return list<array{int, int}>
     */
    private static function subtract(array $ranges, array $excluded): array
    {
        $result = [];
        foreach ($ranges as [$low, $high]) {
            foreach ($excluded as [$exLow, $exHigh]) {
                if ($exHigh < $low || $exLow > $high) {
                    continue;
                }
                if ($exLow > $low) {
                    $result[] = [$low, $exLow - 1];
                }
                $low = $exHigh + 1;
                if ($low > $high) {
                    break;
                }
            }
            if ($low <= $high) {
                $result[] = [$low, $high];
            }
        }

        return $result;
    }
}
