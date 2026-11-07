<?php

declare(strict_types=1);

namespace Pront\Tests\Type;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pront\PreRun\PreRunError;
use Pront\Tests\Type\Fixtures\Overflow;
use Pront\Type\NeverType;
use Pront\Type\ResolvedSignature;
use Pront\Type\TypeResolver;
use Pront\Type\UncheckedType;

/**
 * ADR-0003: every Stage 1 type, every pre-run-error construct, tag precedence, the narrowing check
 * and name resolution, each resolved through real reflection of a generated target.
 */
final class TypeResolverTest extends TestCase
{
    private const string FX = 'Pront\\Tests\\Type\\Fixtures\\';

    /**
     * @param list<string> $tags PHPDoc tag lines of the method
     */
    private static function resolve(array $tags, string $signature, string $classDoc = ''): ResolvedSignature
    {
        $doc = $tags === [] ? '' : "/**\n" . implode('', array_map(static fn(string $t): string => " * {$t}\n", $tags)) . " */\n";

        return (new TypeResolver())->resolve(TargetFactory::method($doc . $signature, $classDoc));
    }

    /**
     * @return list<string>
     */
    private static function messages(ResolvedSignature $signature): array
    {
        return array_map(static fn(PreRunError $e): string => (string) $e, $signature->errors);
    }

    private static function assertOneErrorContaining(string $needle, ResolvedSignature $signature): void
    {
        $messages = self::messages($signature);
        self::assertCount(1, $messages, implode("\n", $messages));
        self::assertStringContainsString($needle, $messages[0]);
    }

    /**
     * Every type of the ADR-0014 section 3 list, as PHPDoc, with the native type it narrows and
     * the description it resolves to.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function stage1Types(): array
    {
        return [
            'int' => ['int', 'int', 'int'],
            'integer synonym' => ['integer', 'int', 'int'],
            'int range' => ['int<-5, 10>', 'int', 'int<-5, 10>'],
            'int range min' => ['int<min, 0>', 'int', 'int<min, 0>'],
            'int range max' => ['int<0, max>', 'int', 'int<0, max>'],
            'int range full' => ['int<min, max>', 'int', 'int'],
            'int range hex and binary' => ['int<0x10, 0b11111>', 'int', 'int<16, 31>'],
            'int range single' => ['int<3, 3>', 'int', 'int<3, 3>'],
            'positive-int' => ['positive-int', 'int', 'int<1, max>'],
            'negative-int' => ['negative-int', 'int', 'int<min, -1>'],
            'non-negative-int' => ['non-negative-int', 'int', 'int<0, max>'],
            'float' => ['float', 'float', 'float'],
            'double synonym' => ['double', 'float', 'float'],
            'bool' => ['bool', 'bool', 'bool'],
            'string' => ['string', 'string', 'string'],
            'non-empty-string' => ['non-empty-string', 'string', 'non-empty-string'],
            'nullable' => ['?int', '?int', 'int|null'],
            'union' => ['int|string', 'int|string', 'int|string'],
            'union with null' => ['string|null', '?string', 'string|null'],
            'int literal' => ['5', 'int', '5'],
            'negative int literal' => ['-5', 'int', '-5'],
            'float literal' => ['1.5', 'float', '1.5'],
            'string literal' => ["'a'", 'string', "'a'"],
            'empty string literal' => ["''", 'string', "''"],
            'true' => ['true', 'bool', 'true'],
            'false' => ['false', 'bool', 'false'],
            'literal union' => ["'a'|'b'", 'string', "'a'|'b'"],
            'list' => ['list<int>', 'array', 'list<int>'],
            'nested list' => ['list<list<positive-int>>', 'array', 'list<list<int<1, max>>>'],
            'non-empty-list' => ['non-empty-list<string>', 'array', 'non-empty-list<string>'],
            'shape' => ['array{a: int, b: string}', 'array', 'array{a: int, b: string}'],
            'shape unkeyed' => ['array{int, string}', 'array', 'array{0: int, 1: string}'],
            'shape mixed keys' => ['array{5: int, x: bool, string}', 'array', 'array{5: int, x: bool, 6: string}'],
            'shape decimal string key' => ["array{'1': int, '01': int, '-0': int}", 'array', "array{1: int, '01': int, '-0': int}"],
            'shape empty' => ['array{}', 'array', 'array{}'],
            'shape nested' => ['array{a: list<?int>}', 'array', 'array{a: list<int|null>}'],
            'array int keys' => ['array<int, string>', 'array', 'array<int, string>'],
            'array string keys' => ['array<string, int>', 'array', 'array<string, int>'],
            'array int|string keys' => ['array<int|string, bool>', 'array', 'array<int|string, bool>'],
            'unit enum' => ['Suit', '\\' . self::FX . 'Suit', self::FX . 'Suit'],
            'backed enum' => ['BackedSuit', '\\' . self::FX . 'BackedSuit', self::FX . 'BackedSuit'],
            'nullable enum' => ['?Suit', '?\\' . self::FX . 'Suit', self::FX . 'Suit|null'],
        ];
    }

    #[DataProvider('stage1Types')]
    public function testStage1TypeResolvesInInputPosition(string $phpDoc, string $native, string $expected): void
    {
        $signature = self::resolve(["@param {$phpDoc} \$x"], "public function m({$native} \$x): void {}");

        self::assertSame([], self::messages($signature));
        self::assertSame($expected, $signature->parameters['x']?->describe());
    }

    #[DataProvider('stage1Types')]
    public function testStage1TypeIsCheckedInReturnPosition(string $phpDoc, string $native, string $expected): void
    {
        $signature = self::resolve(["@return {$phpDoc}"], "public function m(): {$native} {}");

        self::assertSame([], self::messages($signature));
        self::assertSame($expected, $signature->return?->describe());
        self::assertSame([], $signature->uncheckedReturnFragments());
    }

    /**
     * The Stage 1 types that have a native spelling.
     *
     * @return array<string, array{string, string}>
     */
    public static function nativeStage1Types(): array
    {
        return [
            'int' => ['int', 'int'],
            'float' => ['float', 'float'],
            'bool' => ['bool', 'bool'],
            'string' => ['string', 'string'],
            'true' => ['true', 'true'],
            'false' => ['false', 'false'],
            'nullable' => ['?int', 'int|null'],
            'union' => ['int|string|null', 'string|int|null'],
            'unit enum' => ['\\' . self::FX . 'Suit', self::FX . 'Suit'],
            'nullable backed enum' => ['?\\' . self::FX . 'BackedSuit', self::FX . 'BackedSuit|null'],
            'enum alias' => ['Suit', self::FX . 'Suit'],
        ];
    }

    #[DataProvider('nativeStage1Types')]
    public function testStage1NativeTypeWithoutPhpDocResolves(string $native, string $expected): void
    {
        $signature = self::resolve([], "public function m({$native} \$x): void {}");

        self::assertSame([], self::messages($signature));
        self::assertSame($expected, $signature->parameters['x']?->describe());
    }

    /**
     * The checked return set beyond the input types (ADR-0009 section 2): valid as a return type,
     * a pre-run error as a parameter type.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function returnOnlyTypes(): array
    {
        return [
            'void' => ['void', 'void', 'void'],
            'never' => ['never', 'never', 'never'],
            'null' => ['null', '?int', 'null'],
            'mixed' => ['mixed', 'mixed', 'mixed'],
            'self' => ['self', 'self', 'self(%s)'],
            'static' => ['static', 'static', 'static(%s)'],
            'class' => ['Circle', '', self::FX . 'Circle'],
            'interface' => ['Figure', '\\' . self::FX . 'Shape', self::FX . 'Shape'],
            'list of mixed' => ['list', 'array', 'list<mixed>'],
            'array' => ['array', 'array', 'array'],
            'list of classes' => ['list<Circle>', 'array', 'list<' . self::FX . 'Circle>'],
        ];
    }

    #[DataProvider('returnOnlyTypes')]
    public function testReturnOnlyTypeIsCheckedAsReturn(string $phpDoc, string $native, string $expected): void
    {
        $returnType = $native === '' ? '' : ": {$native}";
        $signature = self::resolve(["@return {$phpDoc}"], "public function m(){$returnType} { throw new \\LogicException(); }");

        self::assertSame([], self::messages($signature));
        $class = substr($signature->subject, 0, -strlen('::m()'));
        self::assertSame(sprintf($expected, $class), $signature->return?->describe());
        self::assertSame([], $signature->uncheckedReturnFragments());
    }

    #[DataProvider('returnOnlyTypes')]
    public function testReturnOnlyTypeIsAPreRunErrorAsInput(string $phpDoc, string $native, string $expected): void
    {
        $signature = self::resolve(["@param {$phpDoc} \$x"], 'public function m($x): void {}');

        self::assertNull($signature->parameters['x']);
        self::assertNotSame([], self::messages($signature));
        self::assertStringContainsString('parameter $x', self::messages($signature)[0]);
    }

    /**
     * Constructs outside the Stage 1 list that ADR-0003 item 3 does not list as pre-run errors in
     * every position: unchecked as a return type, a pre-run error as a parameter type.
     *
     * @return array<string, array{string, string}>
     */
    public static function uncheckedTypes(): array
    {
        return [
            'callable' => ['callable(int): string', 'callable(int): string'],
            'generic class' => ['\ArrayIterator<int, int>', '\ArrayIterator<int, int>'],
            'T[]' => ['int[]', 'int[]'],
            'array-key' => ['array-key', 'array-key'],
            'non-falsy-string' => ['non-falsy-string', 'non-falsy-string'],
            'non-positive-int' => ['non-positive-int', 'non-positive-int'],
            'iterable' => ['iterable<int>', 'iterable<int>'],
            'object' => ['object', 'object'],
            'this' => ['$this', '$this'],
            'intersection' => ['Figure&\Countable', 'Figure & \Countable'],
            'array without key' => ['array<int>', 'array<int>'],
            'array with other key' => ['array<positive-int, int>', 'array<positive-int, int>'],
            'non-empty-array' => ['non-empty-array<int, int>', 'non-empty-array<int, int>'],
            'class-string' => ['class-string', 'class-string'],
            'fragment in list' => ['list<callable>', 'callable'],
            'fragment in union' => ['int|scalar', 'scalar'],
            'conditional' => ['($x is int ? int : string)', '($x is int ? int : string)'],
        ];
    }

    #[DataProvider('uncheckedTypes')]
    public function testUnsupportedTypeIsUncheckedAsReturn(string $phpDoc, string $fragment): void
    {
        $signature = self::resolve(["@return {$phpDoc}"], 'public function m() {}');

        self::assertSame([], self::messages($signature));
        $written = array_map(static fn(UncheckedType $u): string => $u->written, $signature->uncheckedReturnFragments());
        self::assertSame([$fragment], $written);
    }

    #[DataProvider('uncheckedTypes')]
    public function testUnsupportedTypeIsAPreRunErrorAsInput(string $phpDoc, string $fragment): void
    {
        $signature = self::resolve(["@param {$phpDoc} \$x"], 'public function m($x): void {}');

        self::assertNull($signature->parameters['x']);
        self::assertOneErrorContaining($fragment, $signature);
    }

    public function testTemplateTypeIsUncheckedAsReturnAndAnErrorAsInput(): void
    {
        $signature = self::resolve(['@template T', '@param T $x', '@return T'], 'public function m($x) {}');

        self::assertNull($signature->parameters['x']);
        self::assertOneErrorContaining('T: template types are not in the Stage 1 list', $signature);
        self::assertSame('unchecked(T)', $signature->return?->describe());
    }

    public function testClassTemplateTypeIsRecognized(): void
    {
        $signature = self::resolve(['@return list<T>'], 'public function m() {}', "/**\n * @template-covariant T\n */");

        self::assertSame([], self::messages($signature));
        self::assertSame('list<unchecked(T)>', $signature->return?->describe());
    }

    /**
     * ADR-0003 item 3: pre-run errors in every position, plus malformed or unresolvable
     * declarations.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function preRunErrorTypes(): array
    {
        $aliasDoc = "/**\n * @phpstan-type Money int<0, max>\n * @psalm-type Cents int\n"
            . " * @phpstan-import-type Imported from \\Pront\\Tests\\Type\\Fixtures\\Circle\n"
            . " * @psalm-import-type Other from \\Pront\\Tests\\Type\\Fixtures\\Circle as Renamed\n */";

        return [
            'phpstan-type alias' => ['Money', 'type alias Money (@phpstan-type) is not supported', $aliasDoc],
            'psalm-type alias' => ['list<Cents>', 'type alias Cents (@psalm-type) is not supported', $aliasDoc],
            'phpstan-import-type' => ['Imported', 'type alias Imported (@phpstan-import-type) is not supported', $aliasDoc],
            'psalm-import-type as' => ['?Renamed', 'type alias Renamed (@psalm-import-type) is not supported', $aliasDoc],
            'class constant bound' => ['int<1, Circle::MAX>', 'bound Circle::MAX in int<1, Circle::MAX> is not an integer literal', ''],
            'self constant bound' => ['int<1, self::MAX>', 'bound self::MAX', ''],
            'global constant bound' => ['int<0, PHP_INT_MAX>', 'bound PHP_INT_MAX', ''],
            'float bound' => ['int<0, 1.5>', 'bound 1.5', ''],
            'swapped min' => ['int<max, 5>', 'bound max', ''],
            'bound overflow' => ['int<0, 9223372036854775808>', "outside PHP's int range", ''],
            'empty range' => ['int<5, 1>', 'integer range int<5, 1> is empty', ''],
            'one bound' => ['int<5>', 'takes exactly two bounds', ''],
            'enum case' => ['Suit::Hearts', 'enum case reference Suit::Hearts is not supported', ''],
            'enum case wildcard' => ['Suit::*', 'enum case reference Suit::*', ''],
            'enum case in union' => ['int|BackedSuit::Hearts', 'enum case reference BackedSuit::Hearts', ''],
            'class constant' => ['Circle::MAX', 'constant expression Circle::MAX is not supported', ''],
            'optional shape key' => ['array{a?: int}', 'optional key a? is not supported', ''],
            'unsealed shape' => ['array{a: int, ...}', 'unsealed shapes (...) are not supported', ''],
            'unsealed typed shape' => ['array{a: int, ...<string, int>}', 'unsealed shapes', ''],
            'list shape' => ['list{int, string}', 'list{...} shapes are not supported', ''],
            'non-empty-array shape' => ['non-empty-array{a: int}', 'non-empty-array{...} shapes', ''],
            'object shape' => ['object{a: int}', 'object shape object{a: int} is not supported', ''],
            'duplicate shape key' => ["array{a: int, 'a': string}", 'duplicate key', ''],
            'int literal overflow' => ['9223372036854775808', "integer literal 9223372036854775808 is outside PHP's int range", ''],
            'unknown class' => ['Nope', 'unknown class Pront\\Tests\\Type\\Generated\\', ''],
            'unknown generic class' => ['Nope<int>', 'unknown class', ''],
            'trait' => ['Fx\Helper', 'is a trait, not a type', ''],
            'list arity' => ['list<int, string>', 'takes exactly one type argument', ''],
        ];
    }

    #[DataProvider('preRunErrorTypes')]
    public function testConstructIsAPreRunErrorAsInput(string $phpDoc, string $message, string $classDoc): void
    {
        $signature = self::resolve(["@param {$phpDoc} \$x"], 'public function m($x): void {}', $classDoc);

        self::assertNull($signature->parameters['x']);
        self::assertOneErrorContaining($message, $signature);
        self::assertStringContainsString('parameter $x: @param ', self::messages($signature)[0]);
    }

    #[DataProvider('preRunErrorTypes')]
    public function testConstructIsAPreRunErrorAsReturn(string $phpDoc, string $message, string $classDoc): void
    {
        $signature = self::resolve(["@return {$phpDoc}"], 'public function m() {}', $classDoc);

        self::assertNull($signature->return);
        self::assertOneErrorContaining($message, $signature);
        self::assertStringContainsString('return type: @return ', self::messages($signature)[0]);
    }

    public function testUnusedAliasIsNoError(): void
    {
        $signature = self::resolve(['@param int<0, 5> $x'], 'public function m(int $x): void {}', "/**\n * @phpstan-type Money int\n */");

        self::assertSame([], self::messages($signature));
    }

    /**
     * Precedence `@param`, `@phan-param`, `@psalm-param`, `@phpstan-param`: the last present wins,
     * whatever the order the tags are written in.
     *
     * @return array<string, array{list<string>, ?string, ?string}>
     */
    public static function paramPrecedence(): array
    {
        return [
            'param alone' => [['@param int<0, 1> $x'], 'int<0, 1>', null],
            'phan over param' => [['@phan-param int<0, 2> $x', '@param int<0, 1> $x'], 'int<0, 2>', null],
            'psalm over phan' => [['@psalm-param int<0, 3> $x', '@phan-param int<0, 2> $x'], 'int<0, 3>', null],
            'phpstan over psalm' => [['@phpstan-param int<0, 4> $x', '@psalm-param int<0, 3> $x'], 'int<0, 4>', null],
            'phpstan over all' => [[
                '@phpstan-param int<0, 4> $x', '@psalm-param int<0, 3> $x',
                '@phan-param int<0, 2> $x', '@param int<0, 1> $x',
            ], 'int<0, 4>', null],
            'last of the same tag' => [['@param int<0, 1> $x', '@param int<0, 9> $x'], 'int<0, 9>', null],
            'unparsable psalm skipped' => [['@param int<0, 1> $x', '@psalm-param int<0, $x'], 'int<0, 1>', null],
            'unparsable psalm over phan skipped' => [['@phan-param int<0, 2> $x', '@psalm-param array{ $x'], 'int<0, 2>', null],
            'unparsable phpstan is an error' => [['@param int<0, 1> $x', '@phpstan-param int<0, $x'], 'int<0, 1>', '@phpstan-param int<0, $x does not parse'],
            'unparsable param is an error' => [['@param int<0, $x'], 'int', '@param int<0, $x does not parse'],
            'unsupported psalm does not fall back' => [['@param int<0, 1> $x', '@psalm-param numeric $x'], null, 'numeric: not in the Stage 1 list'],
            'erroneous phpstan does not fall back' => [['@param int<0, 1> $x', '@phpstan-param int<0, Circle::MAX> $x'], null, 'Circle::MAX'],
            'other parameter untouched' => [['@param int<0, 1> $y', '@phpstan-param int<0, 4> $x'], 'int<0, 4>', null],
        ];
    }

    /**
     * @param list<string> $tags
     */
    #[DataProvider('paramPrecedence')]
    public function testParamTagPrecedence(array $tags, ?string $expected, ?string $error): void
    {
        $signature = self::resolve($tags, 'public function m(int $x, int $y): void {}');

        self::assertSame($expected, $signature->parameters['x']?->describe());
        if ($error === null) {
            self::assertSame([], self::messages($signature));
        } else {
            self::assertOneErrorContaining($error, $signature);
        }
    }

    /**
     * Precedence `@return`, `@phan-return`, `@phan-real-return`, `@psalm-return`, `@phpstan-return`.
     *
     * @return array<string, array{list<string>, ?string, ?string}>
     */
    public static function returnPrecedence(): array
    {
        $all = [
            '@phpstan-return int<0, 4>', '@psalm-return int<0, 3>', '@phan-real-return int<0, 2>',
            '@phan-return int<0, 1>', '@return int<0, 0>',
        ];

        return [
            'all five' => [$all, 'int<0, 4>', null],
            'without phpstan' => [array_slice($all, 1), 'int<0, 3>', null],
            'without phpstan and psalm' => [array_slice($all, 2), 'int<0, 2>', null],
            'phan over return' => [array_slice($all, 3), 'int<0, 1>', null],
            'phan-real over phan' => [['@phan-real-return int<0, 2>', '@phan-return int<0, 1>'], 'int<0, 2>', null],
            'unparsable psalm skipped' => [['@return int<0, 0>', '@psalm-return int<0,'], 'int<0, 0>', null],
            'unparsable phpstan is an error' => [['@return int<0, 0>', '@phpstan-return int<0,'], 'int<0, 0>', '@phpstan-return int<0, does not parse'],
            'unsupported psalm does not fall back' => [['@return int<0, 0>', '@psalm-return numeric'], 'unchecked(numeric)', null],
        ];
    }

    /**
     * @param list<string> $tags
     */
    #[DataProvider('returnPrecedence')]
    public function testReturnTagPrecedence(array $tags, ?string $expected, ?string $error): void
    {
        $signature = self::resolve($tags, 'public function m(): int {}');

        self::assertSame($expected, $signature->return?->describe());
        if ($error === null) {
            self::assertSame([], self::messages($signature));
        } else {
            self::assertOneErrorContaining($error, $signature);
        }
    }

    /**
     * ADR-0003 item 4: a PHPDoc type must be a subtype of the native type.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function narrowing(): array
    {
        return [
            'range in int' => ['int<0, 5>', 'int', true],
            'literal in int' => ['5', 'int', true],
            'literal in string' => ["'a'", 'string', true],
            'literal union in string' => ["'a'|'b'", 'string', true],
            'non-empty-string in string' => ['non-empty-string', 'string', true],
            'true in bool' => ['true', 'bool', true],
            'bool in bool' => ['bool', 'bool', true],
            'float literal in float' => ['1.5', 'float', true],
            'int in union' => ['int', 'int|string', true],
            'union in union' => ['positive-int|non-empty-string', 'int|string|null', true],
            'nullable in nullable' => ['?positive-int', '?int', true],
            'list in array' => ['list<int>', 'array', true],
            'shape in array' => ['array{a: int}', 'array', true],
            'map in array' => ['array<string, int>', 'array', true],
            'enum in interface' => ['Suit', '\UnitEnum', true],
            'enum in nullable enum' => ['Suit', '?\Pront\Tests\Type\Fixtures\Suit', true],
            'anything in mixed' => ['list<int>', 'mixed', true],
            'string over int' => ['string', 'int', false],
            'int over float' => ['int', 'float', false],
            'float over int' => ['float', 'int', false],
            'float literal over int' => ['1.5', 'int', false],
            'string literal over int' => ["'1'", 'int', false],
            'nullable over int' => ['?int', 'int', false],
            'union over int' => ['int<0, 5>|string', 'int', false],
            'bool over true' => ['bool', 'true', false],
            'false over true' => ['false', 'true', false],
            'list over string' => ['list<int>', 'string', false],
            'int over array' => ['int', 'array', false],
            'other enum' => ['Suit', '\Pront\Tests\Type\Fixtures\BackedSuit', false],
            'int over enum' => ['int', '\Pront\Tests\Type\Fixtures\Suit', false],
        ];
    }

    #[DataProvider('narrowing')]
    public function testNarrowingCheckOnParameter(string $phpDoc, string $native, bool $ok): void
    {
        $signature = self::resolve(["@param {$phpDoc} \$x"], "public function m({$native} \$x): void {}");

        if ($ok) {
            self::assertSame([], self::messages($signature));
            self::assertNotNull($signature->parameters['x']);

            return;
        }

        self::assertNull($signature->parameters['x']);
        self::assertOneErrorContaining('is not a subtype of native type', $signature);
        self::assertMatchesRegularExpression('/PHPDoc type \S.* is not a subtype of native type \S/', self::messages($signature)[0]);
    }

    #[DataProvider('narrowing')]
    public function testNarrowingCheckOnReturn(string $phpDoc, string $native, bool $ok): void
    {
        $signature = self::resolve(["@return {$phpDoc}"], "public function m(): {$native} {}");

        self::assertSame($ok, $signature->return !== null);
        if ($ok) {
            self::assertSame([], self::messages($signature));
        } else {
            self::assertOneErrorContaining('is not a subtype of native type', $signature);
        }
    }

    public function testNarrowingMessageNamesBothTypes(): void
    {
        $signature = self::resolve(['@param non-empty-string|null $x'], 'public function m(int|string $x): void {}');

        self::assertSame(
            [$signature->subject . ' parameter $x: PHPDoc type non-empty-string|null is not a subtype of native type string|int'],
            self::messages($signature),
        );
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function classNarrowing(): array
    {
        return [
            'static over self' => ['static', 'self', true],
            'self over static' => ['self', 'static', false],
            'class over interface' => ['Circle', 'Figure', true],
            'interface over class' => ['Figure', 'Circle', false],
            'subclass via namespace alias' => ['Fx\Sub\Square', 'Figure', true],
            'unrelated classes' => ['Circle', 'Fx\Sub\Square', false],
        ];
    }

    #[DataProvider('classNarrowing')]
    public function testClassNarrowingOnReturn(string $phpDoc, string $native, bool $ok): void
    {
        $signature = self::resolve(["@return {$phpDoc}"], "public function m(): {$native} {}");

        self::assertSame($ok, $signature->return !== null);
        if (!$ok) {
            self::assertOneErrorContaining('is not a subtype of native type', $signature);
        }
    }

    public function testUndecidableNarrowingIsAnInputErrorAndAnUncheckedReturn(): void
    {
        $input = self::resolve(['@param list<int> $x'], 'public function m(iterable $x): void {}');
        self::assertNull($input->parameters['x']);
        self::assertOneErrorContaining('cannot check that PHPDoc type list<int> narrows native type unchecked(iterable)', $input);

        $return = self::resolve(['@return list<int>'], 'public function m(): iterable {}');
        self::assertSame([], self::messages($return));
        self::assertSame('unchecked(list<int>)', $return->return?->describe());
    }

    /**
     * ADR-0003 item 3: names resolve with the declaring file's namespace and `use` imports.
     *
     * @return array<string, array{string, string}>
     */
    public static function names(): array
    {
        $generated = 'Pront\\Tests\\Type\\Generated\\';

        return [
            'imported' => ['Circle', self::FX . 'Circle'],
            'imported, other case' => ['circle', self::FX . 'Circle'],
            'group import' => ['BackedSuit', self::FX . 'BackedSuit'],
            'group import alias' => ['Figure', self::FX . 'Shape'],
            'namespace alias prefix' => ['Fx\Sub\Square', self::FX . 'Sub\Square'],
            'fully qualified' => ['\Pront\Tests\Type\Fixtures\Circle', self::FX . 'Circle'],
            'fully qualified global' => ['\ArrayObject', 'ArrayObject'],
            'same namespace' => ['Target', $generated . '%s\Target'],
            'namespace-relative' => ['namespace\Target', $generated . '%s\Target'],
            'parent' => ['parent', 'Exception'],
        ];
    }

    #[DataProvider('names')]
    public function testNameResolution(string $phpDoc, string $expected): void
    {
        $doc = "/**\n * @return {$phpDoc}\n */\n";
        $method = TargetFactory::method($doc . 'public function m() {}', '', '\Exception');
        $signature = (new TypeResolver())->resolve($method);

        self::assertSame([], self::messages($signature));
        $namespaceTail = substr($method->getDeclaringClass()->getNamespaceName(), strlen('Pront\\Tests\\Type\\Generated\\'));
        self::assertSame(sprintf($expected, $namespaceTail), $signature->return?->describe());
    }

    public function testFunctionImportIsNotAClassImport(): void
    {
        $signature = self::resolve(['@return strlen'], 'public function m() {}');

        self::assertOneErrorContaining('\\strlen', $signature);
    }

    public function testClosureResolvesAgainstItsFile(): void
    {
        $closure =
            /**
             * @param positive-int $x
             * @return Overflow
             */
            static fn(int $x): object => new Overflow();
        $signature = (new TypeResolver())->resolve(new \ReflectionFunction($closure));

        self::assertSame([], self::messages($signature));
        self::assertSame('int<1, max>', $signature->parameters['x']?->describe());
    }

    public function testEveryErrorIsCollected(): void
    {
        $signature = self::resolve(
            ['@param int<1, self::MAX> $a', '@param Suit::Hearts $b', '@param string $c', '@return array{a?: int}', '@throws Nope'],
            'public function m(int $a, $b, int $c, $d, int ...$e): array {}',
        );

        $messages = self::messages($signature);
        self::assertCount(7, $messages, implode("\n", $messages));
        foreach (['parameter $a', 'parameter $b', 'parameter $c', 'parameter $d', 'parameter $e', 'return type', '@throws'] as $i => $where) {
            self::assertStringContainsString($where, $messages[$i]);
        }
        self::assertSame([], array_filter($signature->parameters));
        self::assertNull($signature->return);
    }

    public function testParameterWithoutAnyTypeIsAPreRunError(): void
    {
        $signature = self::resolve([], 'public function m($x): void {}');

        self::assertOneErrorContaining('no native or PHPDoc type; mixed cannot be generated', $signature);
    }

    public function testNativeArrayWithoutPhpDocIsAPreRunError(): void
    {
        $signature = self::resolve([], 'public function m(array $x): void {}');

        self::assertOneErrorContaining('array has no element type', $signature);
    }

    public function testClassParameterIsAPreRunErrorUntilStage2(): void
    {
        $signature = self::resolve([], 'public function m(\Pront\Tests\Type\Fixtures\Circle $x): void {}');

        self::assertOneErrorContaining('cannot be generated until Stage 2', $signature);
    }

    public function testVariadicParameterIsAPreRunError(): void
    {
        $signature = self::resolve([], 'public function m(int ...$x): void {}');

        self::assertOneErrorContaining('variadic parameters are not supported in Stage 1', $signature);
    }

    public function testParamTagForUnknownParameterIsAPreRunError(): void
    {
        $signature = self::resolve(['@param int<0, 5> $cuont'], 'public function m(int $count): void {}');

        self::assertOneErrorContaining('@param names $cuont, which is not a parameter', $signature);
    }

    public function testUnknownNativeClassIsAPreRunError(): void
    {
        $signature = self::resolve([], 'public function m(): Nope {}');

        self::assertNull($signature->return);
        self::assertOneErrorContaining('native type', $signature);
    }

    public function testNativeIntersectionReturnIsUnchecked(): void
    {
        $signature = self::resolve([], 'public function m(): Figure&\Countable {}');

        self::assertSame([], self::messages($signature));
        self::assertCount(1, $signature->uncheckedReturnFragments());
    }

    public function testMissingReturnTypeIsMixed(): void
    {
        self::assertSame('mixed', self::resolve([], 'public function m() {}')->return?->describe());
    }

    /**
     * A target declared `never` has undefined behaviour (ADR-0003 item 4); the resolver only
     * represents the declaration.
     */
    public function testNeverReturnIsRepresented(): void
    {
        self::assertInstanceOf(NeverType::class, self::resolve([], 'public function m(): never {}')->return);
    }

    public function testThrowsResolvesNamesAsWritten(): void
    {
        $signature = self::resolve(['@throws Overflow|\LogicException', '@throws \InvalidArgumentException'], 'public function m(): void {}');

        self::assertSame([], self::messages($signature));
        self::assertSame(
            [self::FX . 'Overflow', 'LogicException', 'InvalidArgumentException'],
            $signature->throws?->classes,
        );
    }

    public function testThrowsVoidDeclaresNothing(): void
    {
        $signature = self::resolve(['@throws void'], 'public function m(): void {}');

        self::assertSame([], $signature->throws?->classes);
    }

    public function testNoThrowsTagIsNotChecked(): void
    {
        self::assertNull(self::resolve([], 'public function m(): void {}')->throws);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function badThrows(): array
    {
        return [
            'unknown class' => ['@throws Nope', 'unknown class'],
            'not throwable' => ['@throws Circle', 'is not a Throwable'],
            'not a class name' => ['@throws list<int>', 'is not a class name or void'],
            'void with class' => ['@throws void|\LogicException', '@throws void is combined with exception classes'],
            'unparsable' => ['@throws <', 'does not parse'],
        ];
    }

    #[DataProvider('badThrows')]
    public function testBadThrowsIsAPreRunError(string $tag, string $message): void
    {
        $signature = self::resolve([$tag], 'public function m(): void {}');

        self::assertNull($signature->throws);
        self::assertOneErrorContaining($message, $signature);
    }
}
