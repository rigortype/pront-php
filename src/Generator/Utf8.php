<?php

declare(strict_types=1);

namespace Pront\Generator;

/**
 * UTF-8 encoding of drawn code points, without `mbstring` or `intl` (ADR-0005 keeps them optional).
 */
final class Utf8
{
    /**
     * The UTF-8 bytes of the Unicode scalar value `$codePoint`.
     */
    public static function encode(int $codePoint): string
    {
        if ($codePoint < 0 || $codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)) {
            throw new \InvalidArgumentException(sprintf('U+%04X is not a Unicode scalar value', $codePoint));
        }

        return match (true) {
            $codePoint < 0x80 => chr($codePoint),
            $codePoint < 0x800 => chr(0xC0 | ($codePoint >> 6)) . chr(0x80 | ($codePoint & 0x3F)),
            $codePoint < 0x10000 => chr(0xE0 | ($codePoint >> 12))
                . chr(0x80 | (($codePoint >> 6) & 0x3F))
                . chr(0x80 | ($codePoint & 0x3F)),
            default => chr(0xF0 | ($codePoint >> 18))
                . chr(0x80 | (($codePoint >> 12) & 0x3F))
                . chr(0x80 | (($codePoint >> 6) & 0x3F))
                . chr(0x80 | ($codePoint & 0x3F)),
        };
    }
}
