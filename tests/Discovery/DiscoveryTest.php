<?php

declare(strict_types=1);

namespace Pront\Tests\Discovery;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pront\ChoiceBuffer\ChoiceBuffer;
use Pront\ChoiceBuffer\Prng;
use Pront\Config\Config;
use Pront\Discovery\BindingKind;
use Pront\Discovery\Discovery;
use Pront\Discovery\DiscoveryResult;
use Pront\Discovery\Target;
use Pront\PreRun\PreRunError;

final class DiscoveryTest extends TestCase
{
    private static function php(string $body): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {NS};

            use Pront\\Attribute\\Ensures;
            use Pront\\Attribute\\Verify;

            {$body}
            PHP;
    }

    /**
     * @param array<string, string> $files
     * @return array{Project, DiscoveryResult}
     */
    private static function discover(array $files, ?Config $config = null): array
    {
        $project = new Project($files);

        return [$project, (new Discovery())->discover($config ?? new Config(['src']), $project->directory)];
    }

    /**
     * @return list<string>
     */
    private static function errors(Project $project, DiscoveryResult $result): array
    {
        return array_map(static fn(PreRunError $e): string => $project->relative((string) $e), $result->errors);
    }

    /**
     * @return list<string>
     */
    private static function targetNames(Project $project, DiscoveryResult $result): array
    {
        return array_map(static fn(Target $t): string => $project->relative($t->name()), $result->targets);
    }

    /**
     * Each case: source of `src/A.php`, the expected targets, and one substring per expected
     * pre-run error (the count must match exactly, so nothing extra is reported).
     *
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function cases(): iterable
    {
        yield 'static method' => [
            'final class A { #[Verify] public static function m(int $x): int { return $x; } }',
            ['A::m'],
            [],
        ];
        yield 'instance method, no constructor' => [
            'final class A { #[Verify] public function m(int $x): int { return $x; } }',
            ['A::m'],
            [],
        ];
        yield 'instance method, constructor of Stage 1 types' => [
            'final class A { public function __construct(private int $n, string $s = "") {} #[Verify] public function m(): int { return $this->n; } }',
            ['A::m'],
            [],
        ];
        yield 'enum method' => [
            'enum A { case X; case Y; #[Verify] public function m(bool $b): bool { return $b; } }',
            ['A::m'],
            [],
        ];
        yield 'static method of an abstract class' => [
            'abstract class A { #[Verify] public static function m(int $x): int { return $x; } }',
            ['A::m'],
            [],
        ];
        yield 'constructor takes a class type' => [
            'final class A { public function __construct(\ArrayObject $o) {} #[Verify] public function m(): void {} }',
            [],
            ['A::m(): the receiver cannot be built until Stage 2: A::__construct() parameter $o: class type ArrayObject cannot be generated until Stage 2'],
        ];
        yield 'private constructor' => [
            'final class A { private function __construct() {} #[Verify] public function m(): void {} }',
            [],
            ['A::m(): the receiver cannot be built until Stage 2: A::__construct() is not public'],
        ];
        yield 'instance method of an abstract class' => [
            'abstract class A { #[Verify] public function m(): void {} }',
            [],
            ['A::m(): the receiver cannot be built until Stage 2: A cannot be instantiated'],
        ];
        yield 'enum without cases' => [
            'enum A { #[Verify] public function m(): void {} }',
            [],
            ['A::m(): the receiver cannot be built until Stage 2: enum A has no cases'],
        ];
        yield 'parameter outside the Stage 1 list' => [
            'final class A { #[Verify] public static function m(\ArrayObject $o, mixed $m): void {} }',
            [],
            ['A::m() parameter $o: class type ArrayObject cannot be generated until Stage 2', 'A::m() parameter $m: mixed cannot be generated'],
        ];
        yield 'non-public method' => [
            'final class A { #[Verify] private static function m(): void {} }',
            [],
            ['A::m(): is not public'],
        ];
        yield 'constructor' => [
            'final class A { #[Verify] public function __construct() {} }',
            [],
            ['A::__construct(): a constructor or destructor is not a Stage 1 target'],
        ];
        yield 'iterations below 1' => [
            'final class A { #[Verify(iterations: 0)] public static function m(): void {} }',
            [],
            ['A::m(): #[Pront\Attribute\Verify]: iterations must be at least 1, 0 given'],
        ];
        yield 'argument of the wrong type, PHP\'s own message' => [
            'final class A { #[Verify(iterations: "many")] public static function m(): void {} }',
            [],
            ['A::m(): #[Pront\Attribute\Verify]: Pront\Attribute\Verify::__construct(): Argument #1 ($iterations) must be of type int, string given'],
        ];
        yield 'repeated #[Verify], reported once' => [
            'final class A { #[Verify] #[Verify] public static function m(): void {} }',
            [],
            ['A::m(): #[Pront\Attribute\Verify]: Attribute "Pront\Attribute\Verify" must not be repeated'],
        ];
        yield 'unknown Pront Attribute' => [
            'final class A { #[\Pront\Attribute\Verfy] public static function m(): void {} }',
            [],
            ['A::m(): #[Pront\Attribute\Verfy]: Attribute class "Pront\Attribute\Verfy" not found', 'without #[Verify] on this method'],
        ];
        yield 'aliased namespace' => [
            'use Pront\Attribute as P; final class A { #[P\Verify(seed: 7)] public static function m(): void {} }',
            ['A::m'],
            [],
        ];
        yield 'on a function' => [
            '#[Verify] function f(int $x): int { return $x; }',
            [],
            ['f(): #[Pront\Attribute\Verify]: Attribute "Pront\Attribute\Verify" cannot target function (allowed targets: method)'],
        ];
        yield 'on a class' => [
            '#[Verify] final class A {}',
            [],
            ['A: #[Pront\Attribute\Verify]: Attribute "Pront\Attribute\Verify" cannot target class (allowed targets: method)'],
        ];
        yield 'on a property, a parameter and a constant' => [
            'final class A { #[Verify] public int $p = 0; #[Verify] const C = 1; public static function m(#[Verify] int $x): void {} }',
            [],
            [
                'A::$p: #[Pront\Attribute\Verify]: Attribute "Pront\Attribute\Verify" cannot target property',
                'A::C: #[Pront\Attribute\Verify]: Attribute "Pront\Attribute\Verify" cannot target class constant',
                'A::m() parameter $x: #[Pront\Attribute\Verify]: Attribute "Pront\Attribute\Verify" cannot target parameter',
            ],
        ];
        yield 'on a global constant' => [
            '#[Verify] const G = 1;',
            [],
            ['G: #[Pront\Attribute\Verify]: Attribute "Pront\Attribute\Verify" cannot target constant'],
        ];
        yield 'on a closure' => [
            '$f = #[Verify] static function (): void {};',
            [],
            ['closure at src/A.php:10: #[Pront\Attribute\Verify] is not read: a closure is not a Stage 1 target'],
        ];
        yield 'in an anonymous class' => [
            '$o = new class { #[Verify] public function m(): void {} };',
            [],
            ['anonymous class at src/A.php:10: #[Pront\Attribute\Verify] is not read: anonymous classes are out of scope'],
        ];
        yield 'interface method' => [
            'interface A { #[Verify] public function m(): void; }',
            [],
            ['A::m(): #[Verify] or a contract on an interface or abstract method is unsupported until Stage 3'],
        ];
        yield 'abstract method' => [
            'abstract class A { #[Verify] abstract public function m(): void; }',
            [],
            ['A::m(): #[Verify] or a contract on an interface or abstract method is unsupported until Stage 3'],
        ];
        yield 'trait method' => [
            'trait A { #[Verify] public static function m(): void {} }',
            [],
            ['A::m(): a Pront Attribute on a trait method is not supported in Stage 1'],
        ];
        yield 'a class overriding a verified method' => [
            'class A { #[Verify] public function m(): void {} } final class B extends A { public function m(): void {} }',
            ['A::m'],
            ['B::m(): overrides or implements A::m(), which carries #[Verify] or a contract; unsupported until Stage 3'],
        ];
        yield 'an override through a trait' => [
            'class A { #[Verify] public function m(): void {} } trait T { public function m(): void {} } final class B extends A { use T; }',
            ['A::m'],
            ['B::m(): overrides or implements A::m()'],
        ];
        yield 'a verified override of a verified method' => [
            'class A { #[Verify] public function m(): void {} } final class B extends A { #[Verify] public function m(): void {} }',
            ['A::m'],
            ['B::m(): its parent method A::m() carries #[Verify] or a contract; unsupported until Stage 3'],
        ];
        yield 'an implementation of a verified interface method' => [
            'interface I { #[Verify] public function m(): void; } final class B implements I { public function m(): void {} }',
            [],
            ['I::m(): #[Verify] or a contract on an interface', 'B::m(): overrides or implements I::m()'],
        ];
        yield 'an interface method supplied by a parent' => [
            'interface I { #[Verify] public function m(): void; } class P { public function m(): void {} } final class B extends P implements I {}',
            [],
            ['I::m(): #[Verify] or a contract on an interface', 'B::m(): overrides or implements I::m()'],
        ];
        yield 'an unrelated interface method is unaffected' => [
            'final class A implements \Countable { #[Verify] public static function m(): void {} public function count(): int { return 0; } }',
            ['A::m'],
            [],
        ];
        yield 'two declarations of one class in one file' => [
            'if (PHP_VERSION_ID > 0) { final class A { #[Verify] public static function m(): void {} } } else { final class A {} }',
            [],
            ['src/A.php: not loaded: declares A more than once', 'A::m(): not read, because loading'],
        ];
        yield 'two declarations, the verified one second' => [
            'if (PHP_VERSION_ID < 0) { final class A {} } else { final class A { #[Verify] public static function m(): void {} } }',
            [],
            ['src/A.php: not loaded: declares A more than once', 'A::m(): not read, because loading'],
        ];
        yield 'a closure that is not static' => [
            'final class A { #[Verify] #[Ensures(function (int $return): bool { return true; })] public function m(): int { return 1; } }',
            [],
            ['src/A.php: not loaded: line 10: an Attribute argument holds a closure that is not static', 'A::m(): not read, because loading'],
        ];
        yield 'an arrow function' => [
            'final class A { #[Verify] #[Ensures(fn (int $return): bool => true)] public function m(): int { return 1; } }',
            [],
            ['src/A.php: not loaded: line 10: an Attribute argument holds an arrow function', 'A::m(): not read, because loading'],
        ];
        yield 'a non-static closure inside a static closure is ordinary code' => [
            'final class A { #[Verify] #[Ensures(static function (int $return): bool { $f = fn () => true; return $f(); })] public function m(): int { return 1; } }',
            ['A::m'],
            [],
        ];
        yield 'a #[Verify] without its use import' => [
            'final class A { #[\Verify] public static function m(): void {} #[Tests\Ensures] public static function n(): void {} }',
            [],
            [
                'src/A.php:10: #[Verify] resolves to Verify, which does not exist; Pront\'s Attribute is Pront\Attribute\Verify (is a use import missing?)',
                'src/A.php:10: #[Ensures] resolves to Tests\Ensures, which does not exist; Pront\'s Attribute is Pront\Attribute\Ensures',
            ],
        ];
        yield 'an Attribute of the project\'s own named Verify' => [
            '#[\Attribute] final class MyVerify {} final class A { #[Lib\Verify] public static function m(): void {} }',
            [],
            [],
            "namespace {NS}\\Lib;\n#[\\Attribute] final class Verify {}\n",
        ];
        yield 'a function that is not declared' => [
            'if (PHP_VERSION_ID < 0) { #[Verify] function f(): void {} }',
            [],
            ['f(): cannot be read: Function f() does not exist'],
        ];
        yield 'a private verified method is not a parent contract' => [
            'class A { #[Verify] private static function m(): void {} } final class B extends A { #[Verify] public static function m(): void {} }',
            ['B::m'],
            ['A::m(): is not public'],
        ];
        yield 'a self-typed condition parameter resolves in the condition\'s class' => [
            'final class A { #[Verify] #[Ensures(static function (self $return): bool { return true; })] public function m(): int { return 1; } }',
            [],
            ['parameter $return: its type self cannot accept the bound int'],
        ];
        yield 'one target\'s error leaves the others' => [
            'final class A { #[Verify] public static function ok(): void {} #[Verify] public static function bad(mixed $m): void {} }',
            ['A::ok'],
            ['A::bad() parameter $m: mixed cannot be generated'],
        ];
    }

    /**
     * @param list<string> $targets
     * @param list<string> $errors
     */
    #[DataProvider('cases')]
    public function testDiscovery(string $source, array $targets, array $errors, string $after = ''): void
    {
        [$project, $result] = self::discover(['src/A.php' => self::php($source) . ($after === '' ? '' : "\n{$after}")]);

        self::assertSame($targets, self::targetNames($project, $result));
        self::assertErrors($errors, self::errors($project, $result));
    }

    /**
     * @param list<string> $expected substrings, one per error
     * @param list<string> $actual
     */
    private static function assertErrors(array $expected, array $actual): void
    {
        self::assertCount(count($expected), $actual, implode("\n", $actual));
        foreach ($expected as $substring) {
            $found = array_filter($actual, static fn(string $e): bool => str_contains($e, $substring));
            self::assertNotEmpty($found, "no error contains: {$substring}\nactual:\n" . implode("\n", $actual));
        }
    }

    public function testVerifyArgumentsAndDefaults(): void
    {
        [, $result] = self::discover(['src/A.php' => self::php(
            'final class A { #[Verify] public static function d(): void {} #[Verify(iterations: 5, seed: 42)] public static function s(): void {} }',
        )]);

        self::assertSame([100, null], [$result->targets[0]->verify->iterations, $result->targets[0]->verify->seed]);
        self::assertSame([5, 42], [$result->targets[1]->verify->iterations, $result->targets[1]->verify->seed]);
    }

    public function testTargetsCarryWorkingGenerators(): void
    {
        [, $result] = self::discover(['src/A.php' => self::php(<<<'PHP'
            final class A {
                public function __construct(public int $n) {}
                /** @param int<1, 3> $x */
                #[Verify] public function m(int $x, bool $b): int { return $x; }
                #[Verify] public static function s(): void {}
            }
            enum E { case X; case Y; #[Verify] public function m(): void {} }
            PHP)]);
        [$instance, $static, $enum] = $result->targets;

        $buffer = ChoiceBuffer::fresh(Prng::forTrial(1, 0));
        $arguments = $instance->arguments->generate($buffer);
        self::assertSame(['x', 'b'], array_keys($arguments));
        self::assertContains($arguments['x'], [1, 2, 3]);
        self::assertIsObject($instance->receiver?->generate($buffer));
        self::assertNull($static->receiver);
        self::assertInstanceOf(\UnitEnum::class, $enum->receiver?->generate($buffer));
        self::assertSame([], $static->arguments->generate($buffer));
    }

    /**
     * @return iterable<string, array{string, list<array{BindingKind, ?string}>}>
     */
    public static function bindings(): iterable
    {
        yield 'return, parameter, receiver' => [
            '#[Ensures(static function (int $return, int $x, A $self): bool { return true; })] public function m(int $x): int { return $x; }',
            [[BindingKind::Return, null], [BindingKind::Parameter, 'x'], [BindingKind::Receiver, null]],
        ];
        yield 'prefixed aliases' => [
            '#[Ensures(static function (int $__return, A $__self): bool { return true; })] public function m(): int { return 1; }',
            [[BindingKind::Return, null], [BindingKind::Receiver, null]],
        ];
        yield 'int widens to float in strict mode' => [
            '#[Ensures(static function (float $return): bool { return true; })] public function m(): int { return 1; }',
            [[BindingKind::Return, null]],
        ];
        yield 'a reserved name of a method parameter binds the parameter' => [
            '#[Ensures(static function (string $return, int $__return): bool { return true; })] public function m(string $return): int { return 1; }',
            [[BindingKind::Parameter, 'return'], [BindingKind::Return, null]],
        ];
        yield 'a prefixed name that is not reserved is an ordinary parameter' => [
            '#[Ensures(static function (int $__foo): bool { return true; })] public function m(int $__foo): int { return 1; }',
            [[BindingKind::Parameter, '__foo']],
        ];
        yield 'static method reference' => [
            '#[Ensures([Checks::class, "positive"])] public function m(): int { return 1; }',
            [[BindingKind::Return, null]],
        ];
        yield 'first-class callable' => [
            '#[Ensures(Checks::positive(...))] public function m(): int { return 1; }',
            [[BindingKind::Return, null]],
        ];
        yield 'untyped and object-typed parameters accept anything' => [
            '#[Ensures(static function ($return, object $self): bool { return true; })] public function m(): int { return 1; }',
            [[BindingKind::Return, null], [BindingKind::Receiver, null]],
        ];
    }

    /**
     * @param list<array{BindingKind, ?string}> $expected
     */
    #[DataProvider('bindings')]
    public function testConditionBindings(string $method, array $expected): void
    {
        [$project, $result] = self::discover(['src/A.php' => self::php(
            "final class Checks { public static function positive(int \$return): bool { return \$return > 0; } }\n"
                . "final class A { #[Verify] {$method} }",
        )]);

        self::assertSame([], self::errors($project, $result));
        self::assertCount(1, $result->targets[0]->conditions);
        $bindings = $result->targets[0]->conditions[0]->bindings;
        self::assertSame($expected, array_map(static fn($b): array => [$b->kind, $b->parameter], $bindings));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function conditionErrors(): iterable
    {
        yield 'unknown name' => [
            '#[Verify] #[Ensures(static function (int $y): bool { return true; })] public function m(int $x): void {}',
            ['A::m() #[Ensures] 1 parameter $y: is neither a parameter of A::m() nor a reserved name'],
        ];
        yield '$old is Stage 2' => [
            '#[Verify] #[Ensures(static function (A $old): bool { return true; })] public function m(): void {}',
            ['parameter $old: $old is supported from Stage 2'],
        ];
        yield '$__old is Stage 2' => [
            '#[Verify] #[Ensures(static function (A $__old): bool { return true; })] public function m(): void {}',
            ['parameter $__old: $old is supported from Stage 2'],
        ];
        yield '$return of a void method' => [
            '#[Verify] #[Ensures(static function ($return): bool { return true; })] public function m(): void {}',
            ['parameter $return: a void or never method has no return value'],
        ];
        yield '$self of a static method' => [
            '#[Verify] #[Ensures(static function ($self): bool { return true; })] public static function m(): int { return 1; }',
            ['parameter $self: a static method has no receiver'],
        ];
        yield 'by-reference' => [
            '#[Verify] #[Ensures(static function (int &$return): bool { return true; })] public function m(): int { return 1; }',
            ['parameter $return: a condition parameter cannot be by-reference'],
        ];
        yield 'variadic' => [
            '#[Verify] #[Ensures(static function (int ...$x): bool { return true; })] public function m(int $x): int { return 1; }',
            ['parameter $x: a condition parameter cannot be variadic'],
        ];
        yield 'type that cannot accept the value' => [
            '#[Verify] #[Ensures(static function (string $return, float $x): bool { return true; })] public function m(int $x): int { return 1; }',
            ['parameter $return: its type string cannot accept the bound int'],
        ];
        yield 'receiver of another class' => [
            '#[Verify] #[Ensures(static function (Checks $self): bool { return true; })] public function m(): int { return 1; }',
            ['parameter $self: its type ' . '{NS}\Checks cannot accept the bound {NS}\A'],
        ];
        yield 'prefixed reserved name on the method' => [
            '#[Verify] #[Ensures(static function (int $return): bool { return true; })] public function m(int $__return): int { return 1; }',
            ['A::m() parameter $__return: takes a prefixed reserved name'],
        ];
        yield 'missing static method' => [
            '#[Verify] #[Ensures([Checks::class, "nope"])] public function m(): int { return 1; }',
            ['A::m(): #[Pront\Attribute\Ensures]: ' . '{NS}\Checks::nope() does not exist'],
        ];
        yield 'contract without #[Verify]' => [
            '#[Ensures(static function (int $return): bool { return true; })] public function m(): int { return 1; }',
            ['A::m(): a Pront Attribute without #[Verify] on this method or a parent method would verify nothing'],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('conditionErrors')]
    public function testConditionErrors(string $method, array $expected): void
    {
        [$project, $result] = self::discover(['src/A.php' => self::php(
            "final class Checks {}\nfinal class A { {$method} }",
        )]);

        self::assertSame([], $result->targets);
        self::assertErrors(
            array_map(static fn(string $e): string => str_replace('{NS}\\', '', $e), $expected),
            self::errors($project, $result),
        );
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function reservedNameConflicts(): iterable
    {
        yield 'typed closure literal: no conflict' => ['static function (string $return): bool { return true; }', 0];
        yield 'untyped closure literal' => ['static function ($return): bool { return true; }', 1];
        yield 'mixed closure literal' => ['static function (mixed $return): bool { return true; }', 1];
        yield 'static method reference' => ['[Checks::class, "check"]', 1];
        yield 'first-class callable' => ['Checks::check(...)', 1];
        yield 'closure held in a constant' => ['Checks::CONDITION', 1];
        yield 'prefixed alias: no conflict' => ['static function ($__return): bool { return true; }', 0];
    }

    #[DataProvider('reservedNameConflicts')]
    public function testReservedNameConflicts(string $condition, int $conflicts): void
    {
        [$project, $result] = self::discover(['src/A.php' => self::php(<<<PHP
            final class Checks {
                public const \Closure CONDITION = static function (string \$return): bool { return true; };
                public static function check(string \$return): bool { return true; }
            }
            final class A { #[Verify] #[Ensures({$condition})] public function m(string \$return): int { return 1; } }
            PHP)]);

        self::assertSame([], self::errors($project, $result));
        self::assertCount($conflicts, $result->targets[0]->reservedNameConflicts);
        if ($conflicts > 0) {
            self::assertStringContainsString('use $__return for the reserved meaning', $result->targets[0]->reservedNameConflicts[0]);
        }
    }

    public function testUnverifiedUntilStage3(): void
    {
        [$project, $result] = self::discover([
            'src/A.php' => self::php('class A { #[Verify] public function m(): void {} }'),
            'src/B.php' => self::php('class B extends A { public function other(): void {} }'),
            'src/C.php' => self::php('final class C extends B {}'),
            'src/D.php' => self::php('final class D extends A { public function m(): void {} }'),
            'src/E.php' => self::php('final class E {}'),
        ]);

        self::assertSame(['A::m'], self::targetNames($project, $result));
        self::assertErrors(['D::m(): overrides or implements A::m()'], self::errors($project, $result));
        self::assertSame(
            ['B' => 'A', 'C' => 'A'],
            array_combine(
                array_map($project->relative(...), array_keys($result->unverifiedUntilStage3)),
                array_map($project->relative(...), array_values($result->unverifiedUntilStage3)),
            ),
        );
    }

    public function testParentsAreLoadedBeforeChildren(): void
    {
        // Z.php sorts after A.php but declares A's parent; nothing autoloads it.
        [$project, $result] = self::discover([
            'src/A.php' => self::php('final class A extends Z { #[Verify] public static function m(): void {} }'),
            'src/Z.php' => self::php('abstract class Z {}'),
        ]);

        self::assertSame([], self::errors($project, $result));
        self::assertSame(['A::m'], self::targetNames($project, $result));
    }

    public function testDiscoveringTwiceReusesLoadedClasses(): void
    {
        $project = new Project(['src/A.php' => self::php('final class A { #[Verify] public static function m(): void {} }')]);
        $first = (new Discovery())->discover(new Config(['src']), $project->directory);
        $second = (new Discovery())->discover(new Config(['src']), $project->directory);

        self::assertSame(['A::m'], self::targetNames($project, $first));
        self::assertSame(['A::m'], self::targetNames($project, $second));
        self::assertSame([], $second->errors);
    }

    public function testFileErrors(): void
    {
        [$project, $result] = self::discover([
            'src/Broken.php' => "<?php\nclass {",
            'src/Throws.php' => self::php('throw new \RuntimeException("boom"); final class T { #[Verify] public static function m(): void {} }'),
            'src/Ok.php' => self::php('final class Ok { #[Verify] public static function m(): void {} }'),
            'src/Undeclared.php' => self::php('if (false) { final class U { #[Verify] public static function m(): void {} } }'),
        ]);

        self::assertSame(['Ok::m'], self::targetNames($project, $result));
        self::assertErrors([
            'src/Broken.php: does not parse',
            'src/Throws.php: loading failed: RuntimeException: boom',
            'T::m(): not read, because loading',
            'U::m(): U was not declared by loading',
        ], self::errors($project, $result));
    }

    /**
     * @return iterable<string, array{list<string>, list<string>, list<string>}>
     */
    public static function scopes(): iterable
    {
        yield 'one directory' => [['src'], [], ['A', 'B']];
        yield 'several paths' => [['src', 'tests'], [], ['A', 'B', 'C']];
        yield 'a single file' => [['src/Sub/B.php'], [], ['B']];
        yield 'the project root excludes vendor' => [['.'], [], ['E', 'A', 'B', 'C']];
        yield 'exclude a directory' => [['.'], ['tests'], ['E', 'A', 'B']];
        yield 'exclude with **' => [['.'], ['src/**'], ['E', 'C']];
        yield 'exclude with * stays in a segment' => [['.'], ['*/A.php'], ['E', 'B', 'C']];
        yield 'exclude with **/ at any depth' => [['.'], ['**/Fixtures'], ['A', 'B', 'C']];
        yield 'exclude with **/ at the top' => [['.'], ['**/lib'], ['A', 'B', 'C']];
        yield 'a * does not cross a /' => [['.'], ['*.php'], ['E', 'A', 'B', 'C']];
        yield 'a leading ./ in a pattern' => [['.'], ['./tests'], ['E', 'A', 'B']];
        yield 'a path inside vendor overrides the default' => [['src', 'vendor/acme'], [], ['A', 'B', 'D']];
        yield 'a file inside vendor overrides the default' => [['vendor/acme/D.php'], [], ['D']];
    }

    /**
     * @param list<string> $paths
     * @param list<string> $exclude
     * @param list<string> $classes
     */
    #[DataProvider('scopes')]
    public function testScope(array $paths, array $exclude, array $classes): void
    {
        $files = [];
        foreach (['src/A.php' => 'A', 'src/Sub/B.php' => 'B', 'tests/C.php' => 'C', 'vendor/acme/D.php' => 'D', 'lib/Fixtures/E.php' => 'E'] as $file => $class) {
            $files[$file] = self::php("final class {$class} { #[Verify] public static function m(): void {} }");
        }
        [$project, $result] = self::discover($files, new Config($paths, $exclude));

        self::assertSame([], self::errors($project, $result));
        self::assertSame(array_map(static fn(string $c): string => "{$c}::m", $classes), self::targetNames($project, $result));
    }

    public function testAPathWhoseFilesAreAllExcludedIsAPreRunError(): void
    {
        [$project, $result] = self::discover(
            ['src/A.php' => self::php('final class A {}'), 'tests/T.php' => self::php('final class T {}')],
            new Config(['src', 'tests'], ['tests/**']),
        );

        self::assertSame(['pront.php: paths: every file under tests is excluded'], self::errors($project, $result));
    }

    public function testDuplicateClassAcrossFilesIsNotLoaded(): void
    {
        [$project, $result] = self::discover([
            'src/a.php' => self::php('final class Dup { #[Verify] public static function m(): void {} }'),
            'src/b.php' => self::php('final class Dup {} final class Other { #[Verify] public static function m(): void {} }'),
        ]);

        self::assertSame(['Dup::m'], self::targetNames($project, $result));
        self::assertErrors([
            'src/b.php: not loaded: redeclares Dup, already declared in src/a.php',
            'Other::m(): not read, because loading src/b.php failed',
        ], self::errors($project, $result));
    }

    public function testAVerifiedRedeclarationIsReported(): void
    {
        [$project, $result] = self::discover([
            'src/a.php' => self::php('final class Dup {}'),
            'src/b.php' => self::php('final class Dup { #[Verify] public static function m(): void {} }'),
        ]);

        self::assertSame([], $result->targets);
        self::assertErrors(['src/b.php: not loaded: redeclares Dup, already declared in src/a.php'], self::errors($project, $result));
    }

    public function testDuplicatesThatNeverTouchProntAreLeftAlone(): void
    {
        [$project, $result] = self::discover([
            'src/A.php' => self::php('final class A { #[Verify] public static function m(): void {} }'),
            'src/V1/Foo.php' => self::php('final class Foo {}'),
            'src/V2/Foo.php' => self::php('final class Foo {}'),
        ]);

        self::assertSame([], self::errors($project, $result));
        self::assertSame(['A::m'], self::targetNames($project, $result));
    }

    public function testUnrelatedClassesWithUnloadableParentsAreNotLoaded(): void
    {
        [$project, $result] = self::discover([
            'src/A.php' => self::php('final class A { #[Verify] public static function m(): void {} }'),
            'src/Ctl.php' => self::php('$GLOBALS["pront_ctl_ran"] = true; final class Ctl extends \\Framework\\Controller {}'),
            'src/Iter.php' => self::php('final class Iter implements \\Framework\\Contract, \\Countable { public function count(): int { return 0; } }'),
        ]);

        self::assertSame([], self::errors($project, $result));
        self::assertSame(['A::m'], self::targetNames($project, $result));
        self::assertArrayNotHasKey('pront_ctl_ran', $GLOBALS);
        self::assertFalse(class_exists($project->namespace . '\\Iter', false));
    }

    public function testAClassAlreadyDeclaredFromAnotherFileIsAPreRunError(): void
    {
        $project = new Project([
            'lib/X.php' => self::php('final class X { #[Verify] public static function m(): void {} }'),
            'src/X.php' => self::php('final class X { #[Verify] public static function m(): void {} }'),
        ]);
        require $project->directory . '/lib/X.php';
        $result = (new Discovery())->discover(new Config(['src']), $project->directory);

        self::assertSame([], $result->targets);
        self::assertErrors([
            'src/X.php: X is already declared in',
            'X::m(): not read, because loading src/X.php failed',
        ], self::errors($project, $result));
    }

    public function testAChainThroughAClassOutsidePathsIsFollowedAfterLoading(): void
    {
        $project = new Project([
            'src/A.php' => self::php('class A { #[Verify] public function m(): void {} }'),
            'lib/Mid.php' => self::php('class Mid extends A {}'),
            'src/C.php' => self::php('final class C extends Mid {}'),
        ]);
        $namespace = $project->namespace;
        $autoload = static function (string $class) use ($project, $namespace): void {
            if ($class === "{$namespace}\\Mid") {
                require "{$project->directory}/lib/Mid.php";
            }
        };
        spl_autoload_register($autoload);
        try {
            $result = (new Discovery())->discover(new Config(['src']), $project->directory);
        } finally {
            spl_autoload_unregister($autoload);
        }

        self::assertSame([], self::errors($project, $result));
        self::assertSame(['C'], array_map($project->relative(...), array_keys($result->unverifiedUntilStage3)));
    }

    public function testAnAutoloaderThatThrowsLeavesTheClassUnreported(): void
    {
        $project = new Project([
            'src/A.php' => self::php('class A { #[Verify] public function m(): void {} }'),
            'src/E.php' => self::php('final class E extends Boom {}'),
        ]);
        $namespace = $project->namespace;
        $autoload = static function (string $class) use ($namespace): void {
            if ($class === "{$namespace}\\Boom") {
                throw new \Error('autoloader failed');
            }
        };
        spl_autoload_register($autoload);
        try {
            $result = (new Discovery())->discover(new Config(['src']), $project->directory);
        } finally {
            spl_autoload_unregister($autoload);
        }

        self::assertSame([], self::errors($project, $result));
        self::assertSame(['A::m'], self::targetNames($project, $result));
        self::assertSame([], $result->unverifiedUntilStage3);
    }

    public function testAnAbstractContractClassIsNotListedAgain(): void
    {
        [$project, $result] = self::discover([
            'src/Z.php' => self::php('class Z { #[Verify] public static function s(): void {} }'),
            'src/A.php' => self::php('abstract class A extends Z { #[Verify] abstract public function m(): void; }'),
            'src/B.php' => self::php('final class B extends Z {}'),
        ]);

        self::assertErrors(['A::m(): #[Verify] or a contract on an interface or abstract method'], self::errors($project, $result));
        self::assertSame(['B'], array_map($project->relative(...), array_keys($result->unverifiedUntilStage3)));
    }

    public function testAFatalWhileLoadingEndsWithExitCode2(): void
    {
        $project = new Project([
            'src/A.php' => self::php('class A { #[Verify] public function m(int $x): void {} }'),
            'src/B.php' => self::php('final class B extends A { public function m(string $x): void {} }'),
        ]);
        $script = $project->directory . '/run.php';
        file_put_contents($script, sprintf(
            '<?php require %s; (new Pront\Discovery\Discovery())->discover(new Pront\Config\Config(["src"]), %s); echo "survived";',
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($project->directory, true),
        ));
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        $status = proc_close($process);

        self::assertSame(2, $status, $stderr);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Pront: pre-run error: ' . realpath($project->directory) . '/src/B.php: loading it stopped PHP: Declaration of', $stderr);
    }

    public function testMissingPathIsAPreRunError(): void
    {
        [$project, $result] = self::discover(['src/A.php' => self::php('final class A {}')], new Config(['src', 'nope']));

        self::assertSame(['pront.php: paths: nope does not exist'], self::errors($project, $result));
    }
}
