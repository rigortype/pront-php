<?php

declare(strict_types=1);

namespace Pront\Tests\ChoiceBuffer;

use PHPUnit\Framework\TestCase;
use Pront\ChoiceBuffer\Characters;
use Pront\ChoiceBuffer\InvalidChoice;
use Pront\ChoiceBuffer\Prng;

final class CharactersTest extends TestCase
{
    /**
     * @param list<array{int, int}> $ranges
     */
    private static function contains(array $ranges, int $codePoint): bool
    {
        foreach ($ranges as [$low, $high]) {
            if ($codePoint >= $low && $codePoint <= $high) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{int, int}> $a
     * @param list<array{int, int}> $b
     */
    private static function intersects(array $a, array $b): bool
    {
        foreach ($a as [$lowA, $highA]) {
            foreach ($b as [$lowB, $highB]) {
                if ($lowA <= $highB && $lowB <= $highA) {
                    return true;
                }
            }
        }

        return false;
    }

    public function testClassesAreDisjointAndCoverEveryScalarExceptNoncharacters(): void
    {
        $classes = Characters::classes();
        self::assertCount(count(Characters::WEIGHTS), $classes);
        $noncharacters = Characters::noncharacters();
        self::assertSame(66, Characters::size($noncharacters));
        $excluded = [[0xD800, 0xDFFF], ...$noncharacters];

        $total = 0;
        foreach ($classes as $index => $ranges) {
            $previousHigh = -1;
            foreach ($ranges as [$low, $high]) {
                self::assertGreaterThan($previousHigh, $low, "class {$index} is sorted and disjoint");
                self::assertLessThanOrEqual($high, $low);
                self::assertLessThanOrEqual(0x10FFFF, $high);
                $previousHigh = $high;
            }
            self::assertFalse(self::intersects($ranges, $excluded), "class {$index} has no surrogate or noncharacter");
            foreach ($classes as $other => $otherRanges) {
                if ($other !== $index) {
                    self::assertFalse(self::intersects($ranges, $otherRanges), "classes {$index} and {$other} are disjoint");
                }
            }
            $total += Characters::size($ranges);
        }
        // Disjoint, inside U+0000-U+10FFFF, free of the excluded code points, and this many: so every
        // other scalar value is in exactly one class.
        self::assertSame(0x110000 - 0x800 - 66, $total);

        foreach (Characters::COMBINING as [$low, $high]) {
            foreach ([$low, $high] as $codePoint) {
                self::assertTrue(self::contains($classes[3], $codePoint), sprintf('U+%04X is combining', $codePoint));
            }
        }
        self::assertSame(Characters::size(Characters::COMBINING) + 0x20 + 0x21, Characters::size($classes[3]));
        self::assertSame([[0x20, 0x7E]], $classes[0]);
        self::assertTrue(self::contains($classes[1], 0xA0) && self::contains($classes[1], 0xFFFD));
        self::assertFalse(self::contains($classes[1], 0x0301));
        self::assertFalse(self::contains($classes[2], 0xE0100));
        self::assertFalse(self::contains($classes[2], 0x1FFFE));
        self::assertTrue(self::contains($classes[2], 0x10000) && self::contains($classes[2], 0x10FFFD));
    }

    public function testOrderingStartsWithLettersDigitsSpacePunctuation(): void
    {
        $head = '';
        for ($position = 0; $position < 95; $position++) {
            $head .= chr(Characters::codePointAt($position));
        }
        self::assertSame('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 !"#$%&\'()*+,-./:;<=>?@[\]^_`{|}~', $head);
        self::assertSame(0x00, Characters::codePointAt(95));
        self::assertSame(0x1F, Characters::codePointAt(126));
        self::assertSame(0x7F, Characters::codePointAt(127));
        self::assertSame(0x80, Characters::codePointAt(128));
        self::assertSame(0xD7FF, Characters::codePointAt(0xD7FF));
        self::assertSame(0xE000, Characters::codePointAt(0xD800));
        self::assertSame(0x10FFFF, Characters::codePointAt(Characters::POSITION_COUNT - 1));
    }

    public function testPositionRoundTrip(): void
    {
        $previous = -1;
        foreach ([0, 0x1F, 0x20, 0x7E, 0x7F, 0x80, 0xD7FF, 0xE000, 0xFFFF, 0x10000, 0x10FFFF] as $codePoint) {
            self::assertSame($codePoint, Characters::codePointAt(Characters::positionOf($codePoint)));
        }
        for ($position = 0; $position < 300; $position++) {
            self::assertSame($position, Characters::positionOf(Characters::codePointAt($position)));
        }
        for ($codePoint = 0x80; $codePoint <= 0x10FFFF; $codePoint += 0x101) {
            if ($codePoint >= 0xD800 && $codePoint <= 0xDFFF) {
                continue;
            }
            $position = Characters::positionOf($codePoint);
            self::assertGreaterThan($previous, $position, 'code point order beyond ASCII');
            $previous = $position;
        }
    }

    public function testSurrogatesHaveNoPosition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Characters::positionOf(0xD800);
    }

    public function testPositionPastTheOrderingIsInvalid(): void
    {
        $this->expectException(InvalidChoice::class);
        Characters::codePointAt(Characters::POSITION_COUNT);
    }

    public function testFreshCharactersFollowTheClassWeights(): void
    {
        $prng = Prng::forTrial(12, 0);
        $classes = Characters::classes();
        $counts = [0, 0, 0, 0];
        for ($i = 0; $i < 4000; $i++) {
            $codePoint = Characters::fresh($prng);
            foreach ($classes as $index => $ranges) {
                if (self::contains($ranges, $codePoint)) {
                    $counts[$index]++;
                }
            }
        }
        self::assertSame(4000, array_sum($counts), 'every fresh character is in exactly one class');
        // Expected 2800 / 600 / 400 / 200; wide bounds, a smoke test only.
        self::assertGreaterThan(2600, $counts[0]);
        self::assertGreaterThan(480, $counts[1]);
        self::assertGreaterThan(300, $counts[2]);
        self::assertGreaterThan(130, $counts[3]);
    }
}
