<?php

declare(strict_types=1);

namespace Pront\Tests\Oracle;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pront\Discovery\DiscoveryResult;
use Pront\Discovery\Target;
use Pront\Engine\Input;
use Pront\Oracle\FailureKind;
use Pront\Oracle\Oracle;
use Pront\Oracle\UncheckedFragment;
use Pront\Shrinker\Outcome;
use Pront\Shrinker\OutcomeKind;
use Pront\Tests\Discovery\Project;
use Pront\Tests\Engine\Harness;

final class OracleTest extends TestCase
{
    /**
     * @return array{Project, DiscoveryResult}
     */
    private static function discover(string $code): array
    {
        return Harness::discover(['src/A.php' => Harness::php($code)]);
    }

    private static function target(DiscoveryResult $discovery, string $method = 'm'): Target
    {
        foreach ($discovery->targets as $target) {
            if ($target->method->getName() === $method) {
                return $target;
            }
        }

        throw new \LogicException("No target {$method}");
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function check(Target $target, array $arguments, ?object $receiver = null): Outcome
    {
        $receiver ??= $target->method->isStatic() ? null : $target->method->getDeclaringClass()->newInstance();

        return (new Oracle())($target, new Input($receiver, $arguments));
    }

    /**
     * Each oracle passing and failing: the code of class `A` (target `m`), the arguments, the
     * expected failure kind (null: the trial passes) and a part of the signature.
     *
     * @return iterable<string, array{string, array<string, mixed>, ?FailureKind, ?string}>
     */
    public static function oracles(): iterable
    {
        // Return-type oracle, PHPDoc narrowing a wider native type so a wrong value can leave.
        $positive = 'final class A { /** @return positive-int */ #[Verify] public static function m(int $x): int { return $x; } }';
        yield 'return type holds' => [$positive, ['x' => 1], null, null];
        yield 'return type violated' => [$positive, ['x' => 0], FailureKind::ReturnType, '::m returned int, declared int<1, max>'];
        $untyped = 'final class A { /** @return list<string> */ #[Verify] public static function m(int $x) { return $x === 0 ? ["a"] : [1 => "a"]; } }';
        yield 'PHPDoc only return holds' => [$untyped, ['x' => 0], null, null];
        yield 'PHPDoc only return violated' => [$untyped, ['x' => 1], FailureKind::ReturnType, 'returned array, declared list<string>'];
        $nullable = 'final class A { /** @return ?non-empty-string */ #[Verify] public static function m(int $x): ?string { return [null, "a", ""][$x]; } }';
        yield 'nullable: null' => [$nullable, ['x' => 0], null, null];
        yield 'nullable: value' => [$nullable, ['x' => 1], null, null];
        yield 'nullable: violated' => [$nullable, ['x' => 2], FailureKind::ReturnType, 'returned string, declared non-empty-string|null'];
        $void = 'final class A { /** @return void */ #[Verify] public static function m(int $x) { if ($x === 1) { return 1; } } }';
        yield 'void holds' => [$void, ['x' => 0], null, null];
        yield 'void violated' => [$void, ['x' => 1], FailureKind::ReturnType, 'returned int, declared void'];
        $null = 'final class A { /** @return null */ #[Verify] public static function m(int $x) { return $x === 1 ? false : null; } }';
        yield 'null holds' => [$null, ['x' => 0], null, null];
        yield 'null violated' => [$null, ['x' => 1], FailureKind::ReturnType, 'returned bool, declared null'];
        yield 'mixed holds' => ['final class A { #[Verify] public static function m(int $x): mixed { return new \stdClass(); } }', ['x' => 0], null, null];
        $self = 'class A { /** @return self */ #[Verify] public function m(int $x): object { return $x === 0 ? $this : new \stdClass(); } }';
        yield 'self holds' => [$self, ['x' => 0], null, null];
        yield 'self violated' => [$self, ['x' => 1], FailureKind::ReturnType, 'returned stdClass, declared self('];
        $static = 'class A { /** @return static */ #[Verify] public function m(int $x): object { return $x === 0 ? $this : new \stdClass(); } }';
        yield 'static holds' => [$static, ['x' => 0], null, null];
        yield 'static violated' => [$static, ['x' => 1], FailureKind::ReturnType, 'returned stdClass, declared static('];
        $class = 'final class A { /** @return \Countable */ #[Verify] public static function m(int $x): object { return $x === 0 ? new \ArrayObject() : new \stdClass(); } }';
        yield 'interface name holds' => [$class, ['x' => 0], null, null];
        yield 'interface name violated' => [$class, ['x' => 1], FailureKind::ReturnType, 'returned stdClass, declared Countable'];
        $shape = 'final class A { /** @return array{a: int, b: string} */ #[Verify] public static function m(int $x): array { return [["a" => 1, "b" => "c"], ["a" => 1]][$x]; } }';
        yield 'shape holds' => [$shape, ['x' => 0], null, null];
        yield 'shape violated' => [$shape, ['x' => 1], FailureKind::ReturnType, 'returned array, declared array{a: int, b: string}'];
        $unchecked = 'final class A { /** @return int|scalar */ #[Verify] public static function m(int $x): mixed { return [1, "a", []][$x]; } }';
        yield 'unchecked fragment: the checked member matches' => [$unchecked, ['x' => 0], null, null];
        yield 'unchecked fragment: undecided passes (it is listed as unchecked)' => [$unchecked, ['x' => 1], null, null];
        yield 'unchecked fragment: undecided passes even when it would not hold' => [$unchecked, ['x' => 2], null, null];
        $enumReturn = 'enum E { case X; } final class A { /** @return E */ #[Verify] public static function m(int $x): object { return $x === 0 ? E::X : new \stdClass(); } }';
        yield 'enum holds' => [$enumReturn, ['x' => 0], null, null];
        yield 'enum violated' => [$enumReturn, ['x' => 1], FailureKind::ReturnType, 'returned stdClass, declared'];
        $float = 'final class A { /** @return float */ #[Verify] public static function m(int $x) { return intdiv($x, 1); } }';
        yield 'float accepts an int' => [$float, ['x' => 3], null, null];
        yield 'list<float> accepts ints' => ['final class A { /** @return list<float> */ #[Verify] public static function m(int $x): array { return [$x, 1.5]; } }', ['x' => 3], null, null];
        yield 'float rejects a string' => ['final class A { /** @return float */ #[Verify] public static function m(int $x) { return "1.5"; } }', ['x' => 3], FailureKind::ReturnType, 'returned string, declared float'];
        $nativeMismatch = 'final class A { #[Verify] public static function m(int $x): int { return $x === 0 ? 1 : "a"; } }';
        yield 'native return mismatch is a TypeError, undeclared' => [$nativeMismatch, ['x' => 1], FailureKind::UndeclaredThrowable, 'TypeError@'];

        // @throws oracle (ADR-0009 section 5).
        $throwing = static fn(string $doc, string $throw): string => "final class A { /** {$doc} */ #[Verify] public static function m(int \$x): int { if (\$x === 1) { {$throw}; } return \$x; } }";
        yield 'no @throws: an Exception is not checked' => [$throwing('', 'throw new \DomainException("x")'), ['x' => 1], null, null];
        yield 'no @throws: an Error fails' => [$throwing('', 'intdiv(1, 0)'), ['x' => 1], FailureKind::UndeclaredThrowable, 'DivisionByZeroError@'];
        yield 'no @throws: a returning call passes' => [$throwing('', 'intdiv(1, 0)'), ['x' => 0], null, null];
        yield '@throws the class' => [$throwing('@throws \DomainException', 'throw new \DomainException("x")'), ['x' => 1], null, null];
        yield '@throws a parent class' => [$throwing('@throws \LogicException', 'throw new \DomainException("x")'), ['x' => 1], null, null];
        yield '@throws an interface' => [$throwing('@throws \Throwable', 'intdiv(1, 0)'), ['x' => 1], null, null];
        yield '@throws one of several' => [$throwing('@throws \RangeException|\DomainException', 'throw new \DomainException("x")'), ['x' => 1], null, null];
        yield '@throws other class' => [$throwing('@throws \DomainException', 'throw new \RangeException("x")'), ['x' => 1], FailureKind::UndeclaredThrowable, 'RangeException@'];
        yield '@throws a subclass of the thrown one' => [$throwing('@throws \DomainException', 'throw new \LogicException("x")'), ['x' => 1], FailureKind::UndeclaredThrowable, 'LogicException@'];
        yield '@throws void' => [$throwing('@throws void', 'throw new \DomainException("x")'), ['x' => 1], FailureKind::UndeclaredThrowable, 'DomainException@'];
        yield '@throws an Error' => [$throwing('@throws \DivisionByZeroError', 'intdiv(1, 0)'), ['x' => 1], null, null];
        yield '@throws an Exception does not cover an Error' => [$throwing('@throws \Exception', 'intdiv(1, 0)'), ['x' => 1], FailureKind::UndeclaredThrowable, 'DivisionByZeroError@'];
        yield '@throws resolves use imports' => [
            'use DomainException as Dom; final class A { /** @throws Dom */ #[Verify] public static function m(int $x): int { throw new \DomainException("x"); } }',
            ['x' => 1],
            null,
            null,
        ];
        yield '@phpstan-throws wins' => [$throwing("@throws \DomainException\n * @phpstan-throws \RangeException", 'throw new \DomainException("x")'), ['x' => 1], FailureKind::UndeclaredThrowable, 'DomainException@'];
        yield 'a declared throwable skips the return type and ensures' => [
            "final class A {\n/**\n * @return positive-int\n * @throws \\DivisionByZeroError\n */\n" . '#[Verify] #[\Pront\Attribute\Ensures(static function (): bool { return false; })] public static function m(int $x): int { return intdiv(1, $x); } }',
            ['x' => 0],
            null,
            null,
        ];

        // #[Ensures] conditions (ADR-0009 section 4, Stage 1 subset).
        $ensures = static fn(string $condition, string $body = 'return $x;', string $return = 'int'): string => "final class A { #[Verify] #[\\Pront\\Attribute\\Ensures({$condition})] public static function m(int \$x): {$return} { {$body} } }";
        yield 'ensures holds' => [$ensures('static function (int $return, int $x): bool { return $return === $x; }'), ['x' => 3], null, null];
        yield 'ensures false' => [$ensures('static function (int $return): bool { return $return < 3; }'), ['x' => 3], FailureKind::EnsuresFalse, '::m() #[Ensures] 1'];
        yield 'ensures with the prefixed alias' => [$ensures('static function (int $__return): bool { return $__return < 3; }'), ['x' => 3], FailureKind::EnsuresFalse, '::m() #[Ensures] 1'];
        yield 'ensures sees the parameters' => [$ensures('static function (int $x): bool { return $x < 3; }', 'return 0;'), ['x' => 3], FailureKind::EnsuresFalse, '#[Ensures] 1'];
        yield 'ensures not bool' => [$ensures('static function (int $return) { return $return; }'), ['x' => 3], FailureKind::EnsuresNotBool, '#[Ensures] 1 returned int'];
        yield 'ensures null is not bool' => [$ensures('static function (int $return) { return null; }'), ['x' => 3], FailureKind::EnsuresNotBool, '#[Ensures] 1 returned null'];
        yield 'ensures threw' => [$ensures('static function (int $return): bool { throw new \RuntimeException("no {$return}"); }'), ['x' => 3], FailureKind::EnsuresThrew, '#[Ensures] 1: RuntimeException@'];
        yield 'ensures raised an Error' => [$ensures('static function (int $return): bool { return intdiv(1, $return) > 0; }'), ['x' => 0], FailureKind::EnsuresThrew, '#[Ensures] 1: DivisionByZeroError@'];
        yield 'ensures TypeError binding a value is unverifiable' => [
            "final class A { /** @return int|string */ #[Verify] #[\\Pront\\Attribute\\Ensures(static function (int \$return): bool { return true; })] public static function m(int \$x): int|string { return \$x === 0 ? 0 : 'a'; } }",
            ['x' => 1],
            FailureKind::Unverifiable,
            '#[Ensures] 1: TypeError@',
        ];
        yield 'ensures declared bool returning an int is not bool' => [$ensures('static function (int $return): bool { return $return; }'), ['x' => 3], FailureKind::EnsuresNotBool, '#[Ensures] 1 returned int'];
        yield 'ensures declared bool returning null is not bool' => [$ensures('static function (int $return): bool { return null; }'), ['x' => 3], FailureKind::EnsuresNotBool, '#[Ensures] 1 returned null'];
        yield 'a TypeError from code the ensures calls is a throw' => [$ensures('static function (int $return): bool { return strlen($return) > 0; }'), ['x' => 3], FailureKind::EnsuresThrew, '#[Ensures] 1: TypeError@'];
        yield 'an argument TypeError of a closure the ensures calls is a throw' => [
            $ensures('static function (int $return): bool { $f = static fn(string $s): bool => true; return $f($return); }'),
            ['x' => 3],
            FailureKind::EnsuresThrew,
            '#[Ensures] 1: TypeError@',
        ];
        yield 'ensures is skipped after a return-type failure' => [
            'final class A { /** @return positive-int */ #[Verify] #[\\Pront\\Attribute\\Ensures(static function (): bool { throw new \\LogicException(); })] public static function m(int $x): int { return $x; } }',
            ['x' => 0],
            FailureKind::ReturnType,
            'declared int<1, max>',
        ];
        yield 'the first failing ensures decides' => [
            'final class A { #[Verify] #[\Pront\Attribute\Ensures(static function (): bool { return true; })] #[\Pront\Attribute\Ensures(static function (): bool { return false; })] #[\Pront\Attribute\Ensures(static function (): bool { throw new \LogicException(); })] public static function m(int $x): int { return $x; } }',
            ['x' => 0],
            FailureKind::EnsuresFalse,
            '#[Ensures] 2',
        ];
        yield 'ensures on an instance method binds $self' => [
            'final class A { public int $calls = 0; #[Verify] #[\Pront\Attribute\Ensures(static function (self $self, int $return): bool { return $self->calls === $return; })] public function m(int $x): int { $this->calls++; return $x; } }',
            ['x' => 1],
            null,
            null,
        ];
        yield 'ensures on an instance method binds $__self' => [
            'final class A { public int $calls = 0; #[Verify] #[\Pront\Attribute\Ensures(static function (self $__self, int $return): bool { return $__self->calls === $return; })] public function m(int $x): int { $this->calls++; return $x; } }',
            ['x' => 2],
            FailureKind::EnsuresFalse,
            '#[Ensures] 1',
        ];
        yield 'ensures through a static method reference' => [
            'final class Checks { public static function small(int $return): bool { return $return < 3; } } final class A { #[Verify] #[\Pront\Attribute\Ensures([Checks::class, "small"])] public static function m(int $x): int { return $x; } }',
            ['x' => 3],
            FailureKind::EnsuresFalse,
            '#[Ensures] 1',
        ];
        yield 'ensures on a method parameter named like a reserved name' => [
            'final class A { #[Verify] #[\Pront\Attribute\Ensures(static function (int $return, int $__return): bool { return $return === 7 && $__return === 0; })] public static function m(int $return): int { return 0; } }',
            ['return' => 7],
            null,
            null,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('oracles')]
    public function testOracle(string $code, array $arguments, ?FailureKind $kind, ?string $signature): void
    {
        [, $discovery] = self::discover($code);

        $outcome = self::check(self::target($discovery), $arguments);

        if ($kind === null) {
            self::assertSame(OutcomeKind::Passed, $outcome->kind, (string) $outcome->signature);

            return;
        }
        self::assertSame(OutcomeKind::Failed, $outcome->kind);
        self::assertSame($kind, FailureKind::of($outcome), (string) $outcome->signature);
        self::assertStringContainsString((string) $signature, (string) $outcome->signature);
    }

    public function testStaticIsTheReceiversClass(): void
    {
        [$project, $discovery] = self::discover(
            'class A { /** @return static */ #[Verify] public function m(): object { return new self(); } } final class B extends A {}',
        );
        $target = self::target($discovery);
        $subclass = $project->namespace . '\\B';

        self::assertSame(OutcomeKind::Passed, self::check($target, [])->kind);
        $outcome = self::check($target, [], new $subclass());
        self::assertSame(FailureKind::ReturnType, FailureKind::of($outcome), (string) $outcome->signature);
    }

    /**
     * The same failure keeps its signature however deep the oracle is called, and the signature
     * holds neither the input nor the message.
     *
     * @return iterable<string, array{string, FailureKind}>
     */
    public static function stableFailures(): iterable
    {
        yield 'undeclared throwable' => [
            '/** @throws \DomainException */ #[Verify] public static function m(int $x): int { throw new \RangeException("secret {$x}"); }',
            FailureKind::UndeclaredThrowable,
        ];
        yield 'return type' => ['/** @return negative-int */ #[Verify] public static function m(int $x): int { return $x; }', FailureKind::ReturnType];
        yield 'ensures false' => ['#[Verify] #[\Pront\Attribute\Ensures(static function (int $return): bool { return $return < 0; })] public static function m(int $x): int { return $x; }', FailureKind::EnsuresFalse];
        yield 'ensures threw' => [
            '#[Verify] #[\Pront\Attribute\Ensures(static function (int $return): bool { throw new \LogicException("secret {$return}"); })] public static function m(int $x): int { return $x; }',
            FailureKind::EnsuresThrew,
        ];
        yield 'unverifiable' => [
            '/** @return int|string */ #[Verify] #[\Pront\Attribute\Ensures(static function (string $return): bool { return true; })] public static function m(int $x): int|string { return $x; }',
            FailureKind::Unverifiable,
        ];
    }

    #[DataProvider('stableFailures')]
    public function testAFailureSignatureIsStableAcrossCallPathsAndInputs(string $method, FailureKind $kind): void
    {
        [, $discovery] = self::discover("final class A { {$method} }");
        $target = self::target($discovery);
        $oracle = new Oracle();
        $shallow = $oracle($target, new Input(null, ['x' => 424242]));
        $deep = (static fn(): Outcome => (static fn(): Outcome => $oracle($target, new Input(null, ['x' => 737373])))())();

        self::assertSame($kind, FailureKind::of($shallow));
        self::assertTrue($deep->reproduces($shallow), "{$shallow->signature}\n{$deep->signature}");
        self::assertStringNotContainsString('424242', (string) $shallow->signature);
        self::assertStringNotContainsString('secret', (string) $shallow->signature);
    }

    public function testDifferentThrowSitesAreDifferentFailures(): void
    {
        [, $discovery] = self::discover(<<<'PHP'
            final class A {
                #[Verify] public static function m(int $x): int {
                    if ($x === 0) {
                        return intdiv(1, 0);
                    }
                    return intdiv(1, 0);
                }
            }
            PHP);
        $target = self::target($discovery);

        self::assertFalse(self::check($target, ['x' => 0])->reproduces(self::check($target, ['x' => 1])));
    }

    public function testUncheckedFragmentsAreListedPerTarget(): void
    {
        [$project, $discovery] = self::discover(<<<'PHP'
            final class A {
                /** @return int|scalar */ #[Verify] public static function a(): mixed { return 1; }
                #[Verify] public static function b(): int { return 1; }
                /** @return list<callable(int): string>|array{x: object} */ #[Verify] public static function c(): array { return []; }
            }
            PHP);

        $fragments = array_map(
            static fn(UncheckedFragment $f): string => $project->relative((string) $f),
            Oracle::uncheckedFragments($discovery->targets),
        );

        self::assertCount(3, $fragments);
        self::assertStringStartsWith('A::a: return type fragment scalar is unchecked (', $fragments[0]);
        self::assertStringStartsWith('A::c: return type fragment callable(int): string is unchecked (', $fragments[1]);
        self::assertStringStartsWith('A::c: return type fragment object is unchecked (', $fragments[2]);
        self::assertSame([], Oracle::uncheckedFragments([]));
    }

    public function testAnOutcomeTheOracleDidNotWriteHasNoKind(): void
    {
        self::assertNull(FailureKind::of(Outcome::passed()));
        self::assertNull(FailureKind::of(Outcome::discarded('return type: x')));
        self::assertNull(FailureKind::of(Outcome::fromThrowable(new \RuntimeException())));
        self::assertNull(FailureKind::of(Outcome::failed('return types: x')));
        self::assertSame(FailureKind::EnsuresNotBool, FailureKind::of(Outcome::failed('ensures not bool: x')));
    }

    public function testUncheckedExceptionsAreCountedPerTarget(): void
    {
        [, $discovery] = self::discover(<<<'PHP'
            final class A {
                /** @return positive-int */
                #[Verify]
                #[\Pront\Attribute\Ensures(static function (): bool { return false; })]
                public static function m(int $x): int { if ($x > 0) { throw new \InvalidArgumentException(); } return intdiv(1, 0); }
                /** @throws \InvalidArgumentException */
                #[Verify] public static function declared(int $x): int { throw new \InvalidArgumentException(); }
            }
            PHP);
        $oracle = new Oracle();
        $m = self::target($discovery);

        self::assertSame(OutcomeKind::Passed, $oracle($m, new Input(null, ['x' => 1]))->kind);
        self::assertSame(OutcomeKind::Passed, $oracle($m, new Input(null, ['x' => 2]))->kind);
        self::assertSame(FailureKind::UndeclaredThrowable, FailureKind::of($oracle($m, new Input(null, ['x' => 0]))));
        self::assertSame(OutcomeKind::Passed, $oracle(self::target($discovery, 'declared'), new Input(null, ['x' => 1]))->kind);

        self::assertSame([$m->name() => 2], $oracle->uncheckedExceptions());
        self::assertSame([], (new Oracle())->uncheckedExceptions());
    }
}
