<?php

declare(strict_types=1);

namespace Pront\Generator;

/**
 * The edge-value tables of ADR-0007 section 1 and the in-range candidate lists built from them
 * (ADR-0010 section 4): out-of-range entries are dropped, never clamped, and a range's own endpoints
 * are always candidates. The float table is `FloatCodec::ADR_0007_EDGES` (the only float table).
 *
 * The order of a candidate list is the selector order, so the first candidate is the simplest edge.
 * Changing a table changes which inputs a seed produces (an algorithm-identifier bump, ADR-0011).
 */
final class EdgeValues
{
    /** ADR-0007 section 1, in table order. */
    public const array INT = [
        0, 1, -1, 2, PHP_INT_MAX, PHP_INT_MIN,
        2147483647, 2147483648, -2147483648, 4294967295, 4294967296,
    ];

    /** Valid UTF-8 strings of ADR-0007 section 1, in table order. */
    public const array STRING = [
        // basics
        '', ' ', '0', '00', '1.5', "\n", "\0",
        // Japanese kanji and variants; simplified and traditional Chinese
        '日本語', '髙', '﨑', '汉字', '简体', '漢字', '繁體',
        // supplementary-plane kanji (U+20BB7, U+29E3D, U+2000B) and an ideographic variation sequence
        "\u{20BB7}", "\u{29E3D}", "\u{2000B}", "\u{845B}\u{E0100}",
        // multi-code-point emoji: family (ZWJ), rainbow flag, flag, skin tone, keycap, heart with VS16
        "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}",
        "\u{1F3F3}\u{FE0F}\u{200D}\u{1F308}",
        "\u{1F1EF}\u{1F1F5}",
        "\u{1F44D}\u{1F3FD}",
        "1\u{FE0F}\u{20E3}",
        "\u{2764}\u{FE0F}",
        // normalization and combining: e + U+0301, が versus か + U+3099, full-width and half-width
        // katakana, Hangul jamo, right-to-left Arabic, a lone ZWJ, a BOM
        "e\u{0301}", "\u{304C}", "\u{304B}\u{3099}", 'カタカナ', 'ｶﾀｶﾅ', "\u{1100}\u{1161}\u{11A8}",
        'مرحبا', "\u{200D}", "\u{FEFF}",
    ];

    /** Invalid UTF-8 (ADR-0007 section 1): edge mix only, never the uniform draw. */
    public const array INVALID_UTF8 = ["\xFF", "\xE6\x97"];

    /** The long basic string, `str_repeat('a', 1000)`, appended after the basics. */
    public const int LONG_STRING_BYTES = 1000;

    /**
     * The in-range int candidates: the table entries inside [`$min`, `$max`], then the endpoints
     * that are not already listed.
     *
     * @return list<int>
     */
    public static function ints(int $min, int $max): array
    {
        $candidates = array_values(array_filter(self::INT, static fn(int $v): bool => $v >= $min && $v <= $max));
        foreach ([$min, $max] as $endpoint) {
            if (!in_array($endpoint, $candidates, true)) {
                $candidates[] = $endpoint;
            }
        }

        return $candidates;
    }

    /**
     * The string candidates: the table (with the 1000-byte string after the basics) and the invalid
     * UTF-8 entries. `$nonEmpty` drops `""`; `$arrayKey` drops `"0"`, which PHP turns into the int
     * key `0` (ADR-0007 section 1).
     *
     * @return list<string>
     */
    public static function strings(bool $nonEmpty, bool $arrayKey): array
    {
        $table = self::STRING;
        array_splice($table, 7, 0, [str_repeat('a', self::LONG_STRING_BYTES)]);

        return array_values(array_filter(
            [...$table, ...self::INVALID_UTF8],
            static fn(string $s): bool => !($nonEmpty && $s === '') && !($arrayKey && $s === '0'),
        ));
    }

    /**
     * The length candidates of a list or array (ADR-0007 section 1): empty, one element, and the
     * range's lower and upper bounds, in range and without repeats.
     *
     * @return list<int>
     */
    public static function lengths(int $min, int $max): array
    {
        $candidates = [];
        foreach ([0, 1, $min, $max] as $length) {
            if ($length >= $min && $length <= $max && !in_array($length, $candidates, true)) {
                $candidates[] = $length;
            }
        }

        return $candidates;
    }
}
