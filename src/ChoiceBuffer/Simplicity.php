<?php

declare(strict_types=1);

namespace Pront\ChoiceBuffer;

/**
 * "Simpler" for the shrinker (ADR-0002 sections 2 and 4). Every comparator returns a negative
 * number when the left side is simpler, 0 when equal, positive otherwise.
 */
final class Simplicity
{
    /**
     * Compares two `int` positions `(magnitude, side)` without overflow (ADR-0002 section 2): equal
     * sides compare magnitudes; across sides the distances are `m` (side 0) and `m + 1` (side 1),
     * and the positive side wins a tie.
     */
    public static function compareInt(int $magnitudeA, int $sideA, int $magnitudeB, int $sideB): int
    {
        foreach ([$sideA, $sideB] as $side) {
            if ($side !== 0 && $side !== 1) {
                throw new \InvalidArgumentException("int side {$side} is not 0 or 1");
            }
        }
        if ($magnitudeA < 0 || $magnitudeB < 0) {
            throw new \InvalidArgumentException('int magnitudes are non-negative');
        }
        if ($sideA === $sideB) {
            return $magnitudeA <=> $magnitudeB;
        }
        if ($sideA === 0) {
            return $magnitudeA - $magnitudeB <= 1 ? -1 : 1;
        }

        return $magnitudeB > $magnitudeA && $magnitudeB - $magnitudeA > 1 ? -1 : 1;
    }

    /**
     * Compares two draws by position in their ordering. Draws of different kinds have no common
     * ordering; they compare by `DrawKind` case order so that shortlex stays a total order.
     */
    public static function compareDraws(Draw $a, Draw $b): int
    {
        if ($a->kind !== $b->kind) {
            return array_search($a->kind, DrawKind::cases(), true) <=> array_search($b->kind, DrawKind::cases(), true);
        }

        return match ($a->kind) {
            DrawKind::Int => self::compareInt($a->slots[0], $a->slots[1], $b->slots[0], $b->slots[1]),
            DrawKind::Float => self::compareFloat($a->slots, $b->slots),
            default => self::compareSlots($a->slots, $b->slots),
        };
    }

    /**
     * Shortlex over consumed buffers: fewer draws is simpler; at equal length, draw by draw. Pass
     * `ChoiceBuffer::consumed()`, never a recording: a recording's missing slots only exist once a
     * replay has read them as zeros (ADR-0002 section 4).
     *
     * @param list<Draw> $a
     * @param list<Draw> $b
     */
    public static function compareBuffers(array $a, array $b): int
    {
        $byLength = count($a) <=> count($b);
        if ($byLength !== 0) {
            return $byLength;
        }
        foreach ($a as $index => $draw) {
            $byDraw = self::compareDraws($draw, $b[$index]);
            if ($byDraw !== 0) {
                return $byDraw;
            }
        }

        return 0;
    }

    /**
     * Kind first (0 < 1 < 2); kind 0 by the int rule; kinds 1 and 2 slot by slot (magnitude bits
     * ascending, then positive first; edge index ascending).
     *
     * @param list<int> $a
     * @param list<int> $b
     */
    private static function compareFloat(array $a, array $b): int
    {
        if ($a[0] !== $b[0]) {
            return $a[0] <=> $b[0];
        }

        return $a[0] === 0
            ? self::compareInt($a[1], $a[2], $b[1], $b[2])
            : self::compareSlots($a, $b);
    }

    /**
     * Lexicographic over slots; a prefix is simpler.
     *
     * @param list<int> $a
     * @param list<int> $b
     */
    private static function compareSlots(array $a, array $b): int
    {
        foreach ($a as $index => $slot) {
            if (!array_key_exists($index, $b)) {
                return 1;
            }
            if ($slot !== $b[$index]) {
                return $slot <=> $b[$index];
            }
        }

        return count($a) <=> count($b);
    }
}
