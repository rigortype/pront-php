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
    private static function resolve(
        array $tags,
        string $signature,
        string $classDoc = '',
        string $declaration = 'class Target',
        string $after = '',
    ): ResolvedSignature {
        return (new TypeResolver())->resolve(
            TargetFactory::method(self::doc($tags) . $signature, $classDoc, $declaration, $after),
        );
    }

    /**
     * @param list<string> $tags
     */
    private static function doc(array $tags): string
    {
        return $tags === [] ? '' : "/**\n" . implode('', array_map(static fn(string $t): string => " * {$t}\n", $tags)) . " */\n";
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
            'int range plus sign' => ['int<+1, 9>', 'int', 'int<1, 9>'],
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
            'array-key keys' => ['array<array-key, bool>', 'array', 'array<int|string, bool>'],
            'array of one argument' => ['array<bool>', 'array', 'array<int|string, bool>'],
            'T[]' => ['bool[]', 'array', 'array<int|string, bool>'],
            'nested T[]' => ['list<int>[]', 'array', 'array<int|string, list<int>>'],
            'array-key' => ['array-key', 'int|string', 'int|string'],
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
            'null' => ['null', 'null', 'null'],
            'null over nullable keeps the native member' => ['null', '?int', 'null|int'],
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
            'T[] of unsupported' => ['callable[]', 'callable'],
            'decimal-int-string' => ['decimal-int-string', 'decimal-int-string'],
            'static-closure' => ['static-closure', 'static-closure'],
            'non-falsy-string' => ['non-falsy-string', 'non-falsy-string'],
            'non-positive-int' => ['non-positive-int', 'non-positive-int'],
            'iterable' => ['iterable<int>', 'iterable<int>'],
            'object' => ['object', 'object'],
            'this' => ['$this', '$this'],
            'intersection' => ['Figure&\Countable', 'Figure & \Countable'],
            'array with literal key' => ["array<'a', int>", "array<'a', int>"],
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
            'array arity' => ['array<int, int, int>', 'takes one or two type arguments', ''],
            'hex bound' => ['int<0, 0xFF>', 'PHP reads it as 255, PHPStan 2.x as 0; write it in decimal', ''],
            'binary literal' => ['0b11', 'PHP reads it as 3, PHPStan 2.x as 0', ''],
            'octal literal' => ['010', 'PHP reads it as 8, PHPStan 2.x as 10', ''],
            'invalid octal literal' => ['08', 'not a valid PHP integer literal, PHPStan 2.x as 8', ''],
            'octal shape key' => ['array{010: int}', 'key 010: PHP reads it as 8', ''],
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
        $input = self::resolve(["@param 'strlen' \$x"], 'public function m(callable $x): void {}');
        self::assertNull($input->parameters['x']);
        self::assertOneErrorContaining("cannot check that PHPDoc type 'strlen' narrows native type unchecked(callable)", $input);

        $return = self::resolve(["@return 'strlen'"], 'public function m(): callable {}');
        self::assertSame([], self::messages($return));
        self::assertSame("unchecked('strlen')", $return->return?->describe());
    }

    /**
     * `iterable` and `object` are outside the Stage 1 list, but narrowing them is decidable.
     *
     * @return array<string, array{string, string, ?string}>
     */
    public static function iterableAndObject(): array
    {
        return [
            'list in iterable' => ['list<int>', 'iterable', 'list<int>'],
            'shape in nullable iterable' => ['array{a: int}', '?iterable', 'array{a: int}|null'],
            'enum in object' => ['Suit', 'object', self::FX . 'Suit'],
            'int in iterable' => ['int', 'iterable', null],
            'string in object' => ['string', 'object', null],
        ];
    }

    #[DataProvider('iterableAndObject')]
    public function testNarrowingOfIterableAndObject(string $phpDoc, string $native, ?string $expected): void
    {
        $signature = self::resolve(["@param {$phpDoc} \$x"], "public function m({$native} \$x): void {}");

        self::assertSame($expected, $signature->parameters['x']?->describe());
        if ($expected === null) {
            self::assertOneErrorContaining('is not a subtype of native type', $signature);
        } else {
            self::assertSame([], self::messages($signature));
        }
    }

    /**
     * PHPStan keeps the native union members a PHPDoc type does not overlap
     * (`TypehintHelper::decideType`) and adds null for a null default.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function nativeMembersKept(): array
    {
        return [
            'nullable' => ['positive-int', '?int $x', 'int<1, max>|null'],
            'nullable with null default' => ['positive-int', '?int $x = null', 'int<1, max>|null'],
            'other scalar' => ['positive-int', 'int|string $x', 'int<1, max>|string'],
            'both overlapped' => ["positive-int|'a'", 'int|string $x', "int<1, max>|'a'"],
            'null and int kept' => ["'a'", 'int|string|null $x', "'a'|int|null"],
            'false kept' => ['positive-int', 'int|false $x', 'int<1, max>|false'],
            'explicit null not doubled' => ['?positive-int', '?int $x', 'int<1, max>|null'],
            'untyped null default' => ['positive-int', '$x = null', 'int<1, max>|null'],
            'non-null default' => ['positive-int', 'int $x = 5', 'int<1, max>'],
            'string null default' => ['non-empty-string', "string \$x = 'null'", 'non-empty-string'],
        ];
    }

    #[DataProvider('nativeMembersKept')]
    public function testNativeUnionMembersAreKept(string $phpDoc, string $parameter, string $expected): void
    {
        $signature = self::resolve(["@param {$phpDoc} \$x"], "public function m({$parameter}): void {}");

        self::assertSame([], self::messages($signature));
        self::assertSame($expected, $signature->parameters['x']?->describe());
    }

    public function testNativeUnionMembersAreKeptInReturn(): void
    {
        $signature = self::resolve(['@return list<int>'], 'public function m(): ?array {}');

        self::assertSame([], self::messages($signature));
        self::assertSame('list<int>|null', $signature->return?->describe());
    }

    public function testKeptNativeMemberOutsideTheListIsAnInputError(): void
    {
        $signature = self::resolve(['@param positive-int $x'], 'public function m(int|object $x): void {}');

        self::assertNull($signature->parameters['x']);
        self::assertOneErrorContaining('object: not in the Stage 1 list (in int<1, max>|unchecked(object))', $signature);
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
        $method = TargetFactory::method($doc . 'public function m() {}', '', 'class Target extends \Exception');
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

    public function testPhpstanThrowsWinsOverThrows(): void
    {
        $only = self::resolve(['@phpstan-throws Overflow'], 'public function m(): void {}');
        self::assertSame([self::FX . 'Overflow'], $only->throws?->classes);

        $both = self::resolve(['@throws \LogicException', '@phpstan-throws void'], 'public function m(): void {}');
        self::assertSame([], self::messages($both));
        self::assertSame([], $both->throws?->classes);
    }

    public function testThrowsSelfResolvesAgainstTheDeclaringClass(): void
    {
        $signature = self::resolve(['@throws self'], 'public function m(): void {}', '', 'class Target extends \Exception');

        self::assertSame([], self::messages($signature));
        self::assertSame([substr($signature->subject, 0, -strlen('::m()'))], $signature->throws?->classes);
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
            'not a class name' => ['@throws list<int>', 'list<int> is not a class name or void'],
            'keyword' => ['@throws array', 'array is not a class name or void'],
            'parent without a parent' => ['@throws parent', 'which has no parent class'],
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

    public function testEnumWithoutCasesIsAnInputError(): void
    {
        $signature = self::resolve(['@param Fx\NoCases $x'], 'public function m($x): void {}');

        self::assertOneErrorContaining('has no cases', $signature);
        self::assertSame(self::FX . 'NoCases', self::resolve(['@return Fx\NoCases'], 'public function m() {}')->return?->describe());
    }

    /**
     * PHPStan inherits PHPDoc for slots a method does not declare; Stage 1 reports it instead.
     *
     * @return array<string, array{string, string, ?string}>
     */
    public static function inheritance(): array
    {
        $class = 'class Target extends \Pront\Tests\Type\Fixtures\Documented';
        $interface = 'class Target implements \Pront\Tests\Type\Fixtures\DocumentedContract';
        $doc = static fn(string ...$tags): string => self::doc(array_values($tags));

        return [
            'nothing declared' => [$class, 'public function m(int $n, string $s): string {}',
                'Documented::m() for parameter $n, parameter $s, return type, @throws would be inherited'],
            'parameters declared' => [$class, $doc('@param int<0, 5> $n', '@param string $s') . 'public function m(int $n, string $s): string {}',
                'Documented::m() for return type, @throws would be inherited'],
            'renamed parameter declared by position' => [$class,
                $doc('@param int $count', '@param string $s', '@return string', '@throws void') . 'public function m(int $count, string $s): string {}',
                null],
            'everything declared' => [$class,
                $doc('@param int $n', '@psalm-param string $s', '@return string', '@phpstan-throws void') . 'public function m(int $n, string $s): string {}',
                null],
            'interface' => [$interface, 'public function m(int $n): string {}',
                'DocumentedContract::m() for parameter $n would be inherited'],
            'grandparent reported once' => ['class Target extends \Pront\Tests\Type\Fixtures\Middle',
                $doc('@param int $n', '@param string $s', '@return string') . 'public function m(int $n, string $s): string {}',
                'Documented::m() for @throws would be inherited'],
            'grandparent behind an undocumented override' => ['class Target extends \Pront\Tests\Type\Fixtures\Overriding',
                'public function m(int $n, string $s): string {}',
                'Documented::m() for parameter $n, parameter $s, return type, @throws would be inherited'],
            'ancestor without tags' => ['class Target extends \Pront\Tests\Type\Fixtures\Untagged',
                'public function m(int|string|null $x): int {}', null],
            'restated union in another order' => ['class Target extends \\Pront\\Tests\\Type\\Fixtures\\RestatedUnion',
                'public function m(int|string $x): static { return $this; }', null],
            'unresolvable ancestor parameter' => ['class Target extends \\Pront\\Tests\\Type\\Fixtures\\Broken',
                'public function m(int $x): void {}', 'Broken::m() for parameter $x may be inherited (undecidable in Stage 1)'],
            'unresolvable ancestor @throws' => ['class Target implements \\Pront\\Tests\\Type\\Fixtures\\ThrowsBroken',
                'public function m(): void {}', 'ThrowsBroken::m() for @throws would be inherited'],
            'concrete trait method is not a source' => ['class Target', 'use Fx\\Concrete; public function m(int $x): void {}', null],
            'nested abstract trait method' => ['class Target', 'use Fx\\OuterContracted; public function m(int $x): void {}',
                'Contracted::m() for parameter $x would be inherited'],
            'inherited static is late-bound' => ['class Target extends \\Pront\\Tests\\Type\\Fixtures\\StaticReturning',
                'public function m(): ?self { return $this; }', 'StaticReturning::m() for return type would be inherited'],
            'child narrows the native return' => ['class Target extends \\Pront\\Tests\\Type\\Fixtures\\NarrowedReturn',
                'public function m(): int { return 1; }', 'NarrowedReturn::m() for return type would be inherited'],
            'restated native types' => ['class Target extends \Pront\Tests\Type\Fixtures\Restating', 'public function m(int $x): void {}', null],
            'constructor by name' => ['class Target extends \Pront\Tests\Type\Fixtures\Constructed',
                $doc('@param int $x') . 'public function __construct(int $x, int $b) {} public function m(): void {}',
                null],
            'abstract trait method' => ['class Target', 'use Fx\Contracted; public function m(int $x): void {}',
                'Contracted::m() for parameter $x would be inherited'],
            'no ancestor' => ['class Target', 'public function m(int $n): string {}', null],
        ];
    }

    #[DataProvider('inheritance')]
    public function testInheritedPhpDocIsAPreRunError(string $declaration, string $method, ?string $error): void
    {
        $signature = (new TypeResolver())->resolve(TargetFactory::method($method, '', $declaration));

        if ($error === null) {
            self::assertSame([], self::messages($signature));
        } else {
            self::assertOneErrorContaining($error, $signature);
            self::assertStringContainsString('PHPDoc inheritance is not supported in Stage 1', self::messages($signature)[0]);
        }
    }

    public function testConstructorParametersAreInheritedByName(): void
    {
        $method = TargetFactory::method(
            self::doc(['@param int $x']) . 'public function __construct(int $x, int $b) {} public function m(): void {}',
            '',
            'class Target extends \Pront\Tests\Type\Fixtures\Constructed',
        );
        $signature = (new TypeResolver())->resolve(new \ReflectionMethod($method->class, '__construct'));

        self::assertOneErrorContaining('Constructed::__construct() for parameter $b would be inherited', $signature);
    }

    /**
     * The overlap test that decides which native union members stay (round-2 review): classes and
     * interfaces overlap unless a common instance is impossible; an undecidable member is reported.
     *
     * @return array<string, array{string, string, ?string}>
     */
    public static function nativeMembersByOverlap(): array
    {
        return [
            'class into its interface or another' => ['Circle', 'Figure|\Countable', self::FX . 'Circle'],
            'final class keeps the other interface' => ['Fx\Sub\Square', 'Figure|\Countable', self::FX . 'Sub\Square|Countable'],
            'interface into itself or another' => ['Figure', 'Figure|\Countable', self::FX . 'Shape'],
            'list keeps int from iterable|int' => ['list<int>', 'iterable|int', 'list<int>|int'],
            'int over int|callable is undecidable' => ['int', 'int|callable', null],
            'unrelated classes are disjoint' => ['Circle', 'Circle|\\Exception', self::FX . 'Circle|Exception'],
            'iterable kept beside a non-final class' => ['Circle', 'iterable|Circle', self::FX . 'Circle|unchecked(iterable)'],
            'iterable kept beside a final class' => ['Fx\\Sub\\Square', 'iterable|Fx\\Sub\\Square', self::FX . 'Sub\\Square|unchecked(iterable)'],
            'iterable overlaps a Traversable class' => ['\\ArrayObject', 'iterable|\\ArrayObject', 'ArrayObject'],
            'iterable overlaps an interface' => ['Figure', 'iterable|Figure', self::FX . 'Shape'],
        ];
    }

    #[DataProvider('nativeMembersByOverlap')]
    public function testNativeMembersByOverlap(string $phpDoc, string $native, ?string $expected): void
    {
        $return = self::resolve(["@return {$phpDoc}"], "public function m(): {$native} {}");
        self::assertSame([], self::messages($return));
        self::assertSame($expected ?? "unchecked({$phpDoc})", $return->return?->describe());

        if (str_contains($native, 'callable') || $native === 'iterable|int') {
            $input = self::resolve(["@param {$phpDoc} \$x"], "public function m({$native} \$x): void {}");
            self::assertSame($expected, $input->parameters['x']?->describe());
            if ($expected === null) {
                self::assertOneErrorContaining('cannot decide whether native type member', $input);
            }
        }
    }

    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function classesIntoIterable(): array
    {
        return [
            'Traversable class' => ['\Generator', 'iterable', 'Generator'],
            'Traversable class, nullable' => ['\ArrayObject', '?iterable', 'ArrayObject|null'],
            'class that is not Traversable' => ['Circle', 'iterable', null],
            'interface may be implemented by a Traversable class' => ['Figure', 'iterable', 'unchecked(Figure)'],
            'final class that is not Traversable' => ['Fx\Sub\Square', 'iterable', null],
        ];
    }

    #[DataProvider('classesIntoIterable')]
    public function testClassesIntoIterable(string $phpDoc, string $native, ?string $expected): void
    {
        $signature = self::resolve(["@return {$phpDoc}"], "public function m(): {$native} {}");

        self::assertSame($expected, $signature->return?->describe());
        if ($expected === null) {
            self::assertOneErrorContaining('is not a subtype of native type', $signature);
        } else {
            self::assertSame([], self::messages($signature));
        }
    }

    public function testNeverPhpDocKeepsNoNativeMember(): void
    {
        $signature = self::resolve(['@return never'], 'public function m(): ?int {}');

        self::assertSame([], self::messages($signature));
        self::assertSame('never', $signature->return?->describe());
    }

    public function testNewInAStringDefaultIsNotAnExpression(): void
    {
        $signature = self::resolve(["@param 'new'|'edit' \$mode"], "public function m(string \$mode = 'new'): void {}");

        self::assertSame([], self::messages($signature));
        self::assertSame("'new'|'edit'", $signature->parameters['mode']?->describe());
    }

    public function testNewDefaultIsNeverEvaluated(): void
    {
        $before = \Pront\Tests\Type\Fixtures\Counter::$made;
        $leading = self::resolve(['@param positive-int $x'], 'public function m(int|\Pront\Tests\Type\Fixtures\Counter $x = new \Pront\Tests\Type\Fixtures\Counter()): void {}');
        $nested = self::resolve(['@param positive-int $x'], 'public function m(int|\Pront\Tests\Type\Fixtures\Counter $x = [new \Pront\Tests\Type\Fixtures\Counter()][0]): void {}');

        self::assertSame($before, \Pront\Tests\Type\Fixtures\Counter::$made);
        self::assertStringContainsString('cannot be generated until Stage 2', self::messages($leading)[0]);
        self::assertOneErrorContaining('without running code', $nested);
    }

    public function testNestedTraitKeepsItsOwnScope(): void
    {
        $signature = (new TypeResolver())->resolve(TargetFactory::method('use Fx\OuterTemplated;'));

        self::assertSame([], self::messages($signature));
        self::assertSame('unchecked(X)', $signature->return?->describe());
    }

    public function testNestedTraitInTheSameFileKeepsItsOwnScope(): void
    {
        $traits = "/**\n * @template Y\n */\ntrait GenInner { /** @return Y */ public function m(): mixed { return null; } }\n"
            . "trait GenOuter { use GenInner; }\n";
        $signature = (new TypeResolver())->resolve(TargetFactory::method('use GenOuter;', $traits));

        self::assertSame([], self::messages($signature));
        self::assertSame('unchecked(Y)', $signature->return?->describe());
    }

    public function testInterfacesDeclaredOnOneLineAreDistinctSources(): void
    {
        $interfaces = 'interface J1 { public function m(): ?int; } '
            . "interface J2 { /** @return positive-int */ public function m(): ?int; }\n";
        $signature = (new TypeResolver())->resolve(
            TargetFactory::method('public function m(): ?int { return null; }', $interfaces, 'class Target implements J1, J2'),
        );

        self::assertOneErrorContaining('J2::m() for return type would be inherited', $signature);
    }

    /**
     * The inherited PHPDoc is applied to this method's native type as PHPStan's
     * `TypehintHelper::decideType` does; only a changed type is reported.
     *
     * @return array<string, array{string, ?string}>
     */
    public static function inheritedOverNarrowerNative(): array
    {
        return [
            'not a subtype of int: native kept' => ['public function a(): int { return 1; }', null],
            'string|positive-int over int: native kept' => ['public function b(): int { return 1; }', null],
            'null removed before applying' => ['public function c(): string { return \'x\'; }', 'WiderUnion::c() for return type would be inherited'],
            'undecidable fragment' => ['public function d(): string { return \'x\'; }', 'WiderUnion::d() for return type may be inherited (undecidable in Stage 1)'],
        ];
    }

    #[DataProvider('inheritedOverNarrowerNative')]
    public function testInheritedPhpDocOverANarrowerNativeType(string $method, ?string $error): void
    {
        $name = substr($method, strlen('public function '), 1);
        $generated = TargetFactory::method(
            $method . ' public function m(): void {}',
            '',
            'class Target extends \Pront\Tests\Type\Fixtures\WiderUnion',
        );
        $signature = (new TypeResolver())->resolve(new \ReflectionMethod($generated->class, $name));

        if ($error === null) {
            self::assertSame([], self::messages($signature));
        } else {
            self::assertOneErrorContaining($error, $signature);
        }
    }

    public function testConstructorWithAnotherNativeTypeInheritsNothing(): void
    {
        $method = TargetFactory::method(
            'public function __construct(string $x) {} public function m(): void {}',
            '',
            'class Target extends \Pront\Tests\Type\Fixtures\CtorTyped',
        );
        $signature = (new TypeResolver())->resolve(new \ReflectionMethod($method->class, '__construct'));

        self::assertSame([], self::messages($signature));
    }

    public function testTraitsDeclaredOnOneLineAreDistinctSources(): void
    {
        $traits = 'trait K1 { /** @return positive-int */ abstract public function m(int $x = 0): ?int; } '
            . "trait K2 { /** @param positive-int \$x */ abstract public function m(int \$x = 0): ?int; }\n";
        $signature = (new TypeResolver())->resolve(
            TargetFactory::method('use K1, K2; public function m(int $x = 0): ?int { return null; }', $traits),
        );

        $messages = self::messages($signature);
        self::assertCount(2, $messages, implode("\n", $messages));
        self::assertStringContainsString('K1::m() for return type would be inherited', $messages[0]);
        self::assertStringContainsString('K2::m() for parameter $x would be inherited', $messages[1]);
    }

    public function testTraitReachedTwiceIsOneSource(): void
    {
        $parent = "abstract class AbstractContracted { use \\Pront\\Tests\\Type\\Fixtures\\Contracted; }\n";
        $signature = (new TypeResolver())->resolve(TargetFactory::method(
            'use Fx\Contracted; public function m(int $x): void {}',
            $parent,
            'class Target extends AbstractContracted',
        ));

        self::assertOneErrorContaining('Contracted::m() for parameter $x would be inherited', $signature);
    }

    public function testDeepAncestorChainsResolveOnce(): void
    {
        $code = '';
        for ($i = 0; $i < 25; $i++) {
            $parent = $i === 0 ? '' : ' extends D' . ($i - 1);
            $code .= "class D{$i}{$parent} { /** @param int<0, {$i}> \$x */ public function m(int \$x): void {} }\n";
        }
        $start = microtime(true);
        $signature = self::resolve(['@param int<0, 99> $x'], 'public function m(int $x): void {}', '', 'class Target extends D24', $code);

        self::assertSame([], self::messages($signature));
        self::assertLessThan(5.0, microtime(true) - $start);
    }

    public function testConstantNullDefaultAddsNull(): void
    {
        $signature = self::resolve(['@param positive-int $x'], 'public function m($x = self::NUL): void {} const NUL = null;');

        self::assertSame([], self::messages($signature));
        self::assertSame('int<1, max>|null', $signature->parameters['x']?->describe());
    }

    public function testUnevaluableDefaultIsAPreRunError(): void
    {
        $signature = self::resolve(['@param positive-int $x'], 'public function m($x = PRONT_UNDEFINED_CONSTANT): void {}');

        self::assertNull($signature->parameters['x']);
        self::assertOneErrorContaining('PRONT_UNDEFINED_CONSTANT cannot be evaluated: Undefined constant', $signature);
    }

    public function testThrowsParentResolvesToTheParentClass(): void
    {
        $signature = self::resolve(['@throws parent'], 'public function m(): void {}', '', 'class Target extends \RuntimeException');

        self::assertSame(['RuntimeException'], $signature->throws?->classes);
    }

    public function testAliasedTraitMethodKeepsTheTraitScope(): void
    {
        $method = TargetFactory::method('use Fx\WithAlias { m as aliased; }');
        $signature = (new TypeResolver())->resolve(new \ReflectionMethod($method->class, 'aliased'));

        self::assertOneErrorContaining('type alias TraitMoney (@phpstan-type) is not supported', $signature);
    }

    public function testPrivateParentMethodIsNotInherited(): void
    {
        $method = TargetFactory::method(
            'private function hidden(int $n): void {} public function m(int $n, string $s): string {}',
            '',
            'class Target extends \Pront\Tests\Type\Fixtures\Documented',
        );
        $signature = (new TypeResolver())->resolve(new \ReflectionMethod($method->class, 'hidden'));

        self::assertSame([], self::messages($signature));
    }

    public function testTraitAliasesComeFromTheTrait(): void
    {
        $signature = (new TypeResolver())->resolve(TargetFactory::method('use Fx\WithAlias;'));

        self::assertNull($signature->return);
        self::assertOneErrorContaining('type alias TraitMoney (@phpstan-type) is not supported', $signature);
    }

    public function testNativeParentResolvesToTheParentClass(): void
    {
        $signature = self::resolve([], 'public function m(): parent {}', '', 'class Target extends \Exception');

        self::assertSame('Exception', $signature->return?->describe());
    }

    public function testClosureSelfResolvesAgainstItsScope(): void
    {
        $closure =
            /**
             * @return self
             */
            static fn(): mixed => null;
        $signature = (new TypeResolver())->resolve(new \ReflectionFunction($closure));

        self::assertSame([], self::messages($signature));
        self::assertSame('self(' . self::class . ')', $signature->return?->describe());
    }

    public function testImportAfterTheDeclarationDoesNotApply(): void
    {
        $signature = self::resolve(['@return Square'], 'public function m() {}', '', 'class Target', 'use Pront\Tests\Type\Fixtures\Sub\Square;');

        self::assertOneErrorContaining('unknown class Pront\Tests\Type\Generated\\', $signature);
    }

    public function testSelfEnumCaseReferenceIsDetected(): void
    {
        $signature = self::resolve(['@return self::Hearts'], 'public function m() {}', '', 'enum Target', '');

        self::assertOneErrorContaining('enum case reference self::Hearts', $signature);
    }

    public function testEvaluatedCodeFallsBackToTheClassNamespace(): void
    {
        eval(<<<'PHP'
            namespace Pront\Tests\Type\Evaluated;
            class Target { /** @return Target */ public function m() {} }
            PHP);
        $signature = (new TypeResolver())->resolve(new \ReflectionMethod('Pront\Tests\Type\Evaluated\Target', 'm'));

        self::assertSame([], self::messages($signature));
        self::assertSame('Pront\Tests\Type\Evaluated\Target', $signature->return?->describe());
    }
}
