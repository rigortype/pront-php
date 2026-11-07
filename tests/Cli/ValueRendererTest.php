<?php

declare(strict_types=1);

namespace Pront\Tests\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pront\Cli\ValueRenderer;
use Pront\Tests\Type\Fixtures\Suit;

final class ValueRendererTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function values(): iterable
    {
        yield 'null' => [null, 'null'];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'zero' => [0, '0'];
        yield 'negative int' => [-5, '-5'];
        yield 'PHP_INT_MIN' => [PHP_INT_MIN, 'PHP_INT_MIN'];
        yield 'PHP_INT_MIN + 1' => [PHP_INT_MIN + 1, '-9223372036854775807'];
        yield 'PHP_INT_MAX' => [PHP_INT_MAX, '9223372036854775807'];
        yield 'float zero' => [0.0, '0.0'];
        yield 'negative zero' => [-0.0, '-0.0'];
        yield 'integral float' => [1.0, '1.0'];
        yield 'negative integral float' => [-3.0, '-3.0'];
        yield 'shortest decimal' => [0.1, '0.1'];
        yield 'large float' => [1.5e300, '1.5E300'];
        yield 'smallest subnormal' => [5e-324, '5.0E-324'];
        yield 'negative smallest subnormal' => [-5e-324, '-5.0E-324'];
        yield 'epsilon' => [PHP_FLOAT_EPSILON, '2.220446049250313E-16'];
        yield 'PHP_FLOAT_MAX' => [PHP_FLOAT_MAX, '1.7976931348623157E308'];
        yield 'NAN' => [NAN, 'NAN'];
        yield 'INF' => [INF, 'INF'];
        yield '-INF' => [-INF, '-INF'];
        yield 'empty string' => ['', '""'];
        yield 'space and digits' => [' 0', '" 0"'];
        yield 'quote, dollar and backslash' => ['a"b$c\\', '"a\"b\$c\\\\"'];
        yield 'ASCII controls' => ["\n\r\t\v\e\f", '"\n\r\t\v\e\f"'];
        yield 'NUL and DEL' => ["\0\x7F", '"\x00\x7F"'];
        yield 'invalid UTF-8 byte' => ["\xFF", '"\xFF"'];
        yield 'truncated UTF-8 sequence' => ["a\xE3\x81", '"a\xE3\x81"'];
        yield 'overlong encoding' => ["\xC0\xAF", '"\xC0\xAF"'];
        yield 'encoded surrogate' => ["\xED\xA0\x80", '"\xED\xA0\x80"'];
        yield 'kanji stay readable' => ['日本語', '"日本語"'];
        yield 'supplementary-plane kanji' => ['𠮷', '"𠮷"'];
        yield 'combining acute accent' => ["e\u{301}", '"e\u{301}"'];
        yield 'variation selector' => ["葛\u{E0100}", '"葛\u{E0100}"'];
        yield 'byte order mark' => ["\u{FEFF}", '"\u{FEFF}"'];
        yield 'zero width joiner in an emoji' => ["👨\u{200D}👩", '"👨\u{200D}👩"'];
        yield 'no-break space' => ["\u{A0}", '"\u{A0}"'];
        yield 'next line control' => ["\u{85}", '"\u{85}"'];
        yield 'Hangul filler' => ["\u{3164}", '"\u{3164}"'];
        yield 'blank Braille pattern' => ["\u{2800}", '"\u{2800}"'];
        yield 'flag' => ['🇯🇵', '"🇯🇵"'];
        yield 'empty array' => [[], '[]'];
        yield 'nested list' => [[1, [2, 'a']], '[1, [2, "a"]]'];
        yield 'shape' => [['a' => 1, 'b' => [true]], '["a" => 1, "b" => [true]]'];
        yield 'int keys not a list' => [[1 => 'x', 0 => 'y'], '[1 => "x", 0 => "y"]'];
        yield 'enum case' => [Suit::Spades, Suit::class . '::Spades'];
        yield 'enum in a list' => [[Suit::Hearts], '[' . Suit::class . '::Hearts]'];
        yield 'other object' => [new \stdClass(), 'object(stdClass)'];
    }

    #[DataProvider('values')]
    public function testRendersAPhpLiteral(mixed $value, string $expected): void
    {
        self::assertSame($expected, ValueRenderer::render($value));
    }

    /**
     * Fidelity: every rendered scalar and array of scalars is PHP code that evaluates back to the
     * identical value (NAN to NAN, -0.0 to -0.0, invalid UTF-8 to the same bytes).
     *
     * @return iterable<string, array{mixed}>
     */
    public static function roundTrips(): iterable
    {
        foreach (self::values() as $name => [$value]) {
            if (!is_object($value) && !(is_array($value) && in_array(Suit::Hearts, $value, true))) {
                yield $name => [$value];
            }
        }
        yield 'every byte' => [implode('', array_map(chr(...), range(0, 255)))];
        yield 'third' => [1 / 3];
        yield 'negative large' => [-123456789.125e-200];
    }

    #[DataProvider('roundTrips')]
    public function testEvaluatesBackToTheSameValue(mixed $value): void
    {
        $code = ValueRenderer::render($value);
        $back = eval("return {$code};");

        if (is_float($value) && is_nan($value)) {
            self::assertIsFloat($back);
            self::assertNan($back);

            return;
        }
        self::assertSame(serialize($value), serialize($back), $code);
    }

    public function testFloatsIgnoreThePrecisionSettings(): void
    {
        $precision = ini_get('precision');
        $serialize = ini_get('serialize_precision');
        ini_set('precision', '3');
        ini_set('serialize_precision', '5');
        try {
            self::assertSame('0.30000000000000004', ValueRenderer::render(0.1 + 0.2));
        } finally {
            ini_set('precision', (string) $precision);
            ini_set('serialize_precision', (string) $serialize);
        }
    }
}
