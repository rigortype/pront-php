<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * Parses a PHPDoc integer literal as PHP does (decimal, `0x`, `0b`, `0o` and leading-`0` octal,
 * `_` separators, optional sign), or null when the value is outside PHP's int range.
 */
final class IntegerLiteral
{
    /**
     * Why a literal must not be used, or null when it is plain decimal. PHPStan 2.x reads an
     * integer literal with an `(int)` cast, so `0x1F`, `0b11` and `0o17` read as 0 and `010` as 10,
     * unlike PHP; Pront rejects them instead of picking one reading.
     */
    public static function ambiguity(string $literal): ?string
    {
        $digits = ltrim(str_replace('_', '', $literal), '+-');
        if (strlen($digits) < 2 || $digits[0] !== '0') {
            return null;
        }

        $php = self::parse($literal);

        return ($php === null ? 'not a valid PHP integer literal' : "PHP reads it as {$php}")
            . ', PHPStan 2.x as ' . (int) $literal . '; write it in decimal';
    }

    public static function parse(string $literal): ?int
    {
        $text = strtolower(str_replace('_', '', $literal));
        $negative = false;
        if ($text !== '' && ($text[0] === '-' || $text[0] === '+')) {
            $negative = $text[0] === '-';
            $text = substr($text, 1);
        }

        [$base, $digits] = match (true) {
            str_starts_with($text, '0x') => [16, substr($text, 2)],
            str_starts_with($text, '0b') => [2, substr($text, 2)],
            str_starts_with($text, '0o') => [8, substr($text, 2)],
            strlen($text) > 1 && $text[0] === '0' => [8, substr($text, 1)],
            default => [10, $text],
        };
        if ($digits === '') {
            return null;
        }

        // Accumulate the negated value so PHP_INT_MIN fits.
        $value = 0;
        foreach (str_split($digits) as $char) {
            $digit = intval($char, 36);
            if ($digit >= $base || ($digit === 0 && $char !== '0')) {
                return null;
            }
            if ($value < intdiv(PHP_INT_MIN + $digit, $base)) {
                return null;
            }
            $value = $value * $base - $digit;
        }

        if ($negative) {
            return $value;
        }

        return $value === PHP_INT_MIN ? null : -$value;
    }
}
