<?php

declare(strict_types=1);

namespace Pront\Cli;

/**
 * Renders a generated value as a PHP literal, so a counterexample reads exactly as drawn:
 *
 * - a string is double-quoted; control characters, separators other than the ASCII space,
 *   combining marks and other invisible or format code points are escaped as `\u{...}` (ASCII
 *   controls as `\n`, `\t`, ... or `\xHH`), and every byte that is not part of valid UTF-8 as `\xHH`;
 * - a float always shows a decimal point or exponent, keeps its sign on `-0.0`, and is the
 *   shortest decimal that reads back to the same float; `NAN`, `INF` and `-INF` are named;
 * - `PHP_INT_MIN` is named, because its decimal literal reads back as a float;
 * - an array is `[...]`, with keys unless it is a list; an enum case is `Class::Case`.
 *
 * Objects other than enum cases are not Stage 1 inputs (a receiver is rendered from its
 * constructor arguments by the caller); one is shown by class only.
 */
final class ValueRenderer
{
    /** A valid UTF-8 sequence (RFC 3629), or any single byte. */
    private const string UTF8_OR_BYTE = '/[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]'
        . '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}'
        . '|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}|[\x80-\xFF]/s';

    private const array ASCII_ESCAPES = [
        "\n" => '\n', "\r" => '\r', "\t" => '\t', "\v" => '\v', "\e" => '\e', "\f" => '\f',
        '\\' => '\\\\', '"' => '\"', '$' => '\$',
    ];

    public static function render(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            $value === PHP_INT_MIN => 'PHP_INT_MIN', // its decimal literal would read back as a float
            is_int($value) => (string) $value,
            is_float($value) => self::float($value),
            is_string($value) => self::string($value),
            is_array($value) => self::array($value),
            $value instanceof \UnitEnum => $value::class . '::' . $value->name,
            is_object($value) => 'object(' . $value::class . ')',
            default => get_debug_type($value),
        };
    }

    public static function float(float $value): string
    {
        if (is_nan($value)) {
            return 'NAN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'INF' : '-INF';
        }
        if ($value === 0.0) {
            return fdiv(1.0, $value) < 0 ? '-0.0' : '0.0';
        }
        // The shortest %G form that reads back to the same float; independent of the
        // `precision` and `serialize_precision` settings the code under test may have changed.
        for ($digits = 1; $digits <= 17; $digits++) {
            $text = sprintf("%.{$digits}G", $value);
            if ((float) $text === $value) {
                break;
            }
        }
        if (str_contains($text, 'E')) {
            [$mantissa, $exponent] = explode('E', $text);
            if (!str_contains($mantissa, '.')) {
                $mantissa .= '.0';
            }

            return $mantissa . 'E' . ltrim($exponent, '+');
        }

        return str_contains($text, '.') ? $text : $text . '.0';
    }

    public static function string(string $value): string
    {
        preg_match_all(self::UTF8_OR_BYTE, $value, $matches);
        $out = '';
        foreach ($matches[0] as $unit) {
            if (strlen($unit) === 1) {
                $byte = ord($unit);
                $out .= self::ASCII_ESCAPES[$unit]
                    ?? ($byte < 0x20 || $byte >= 0x7F ? sprintf('\x%02X', $byte) : $unit);
                continue;
            }
            // Invisible, combining or format code points would hide in the terminal or merge with
            // the quote; spell them out.
            $out .= preg_match('/\A[\p{C}\p{Z}\p{M}]\z/u', $unit) === 1
                ? sprintf('\u{%X}', mb_ord($unit, 'UTF-8'))
                : $unit;
        }

        return '"' . $out . '"';
    }

    /**
     * @param array<mixed> $value
     */
    private static function array(array $value): string
    {
        $list = array_is_list($value);
        $items = [];
        foreach ($value as $key => $item) {
            $items[] = ($list ? '' : self::render($key) . ' => ') . self::render($item);
        }

        return '[' . implode(', ', $items) . ']';
    }
}
