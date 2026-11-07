<?php

declare(strict_types=1);

namespace Pront\Tests\Oracle;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pront\ChoiceBuffer\ChoiceBuffer;
use Pront\Engine\Engine;
use Pront\Engine\TargetStatus;
use Pront\Journal\Journal;
use Pront\Oracle\FailureKind;
use Pront\Oracle\Oracle;
use Pront\Tests\Engine\Harness;

/**
 * The oracle as the engine's property, end to end: a failure an oracle finds is shrunk (its
 * signature is stable between the fresh trial and the shrink candidates), re-run and replayed.
 */
final class OracleEngineTest extends TestCase
{
    private const int RUN_SEED = 22;

    private ?Journal $journal = null;

    protected function tearDown(): void
    {
        $this->journal?->finish();
        $this->journal = null;
    }

    /**
     * A target `A::m(int<-10000, 10000> $x)` that fails exactly when `$x >= 100`, one per failure
     * kind, so the minimal counterexample is `$x = 100`. The seed is pinned (the derived one depends
     * on the generated namespace): a fresh failure at the edge value 10000 is already minimal under
     * ADR-0002's ordering (an edge value takes one draw) and would not shrink to 100.
     *
     * @return iterable<string, array{string, FailureKind}>
     */
    public static function failures(): iterable
    {
        $doc = static fn(string ...$tags): string => "/**\n * @param int<-10000, 10000> \$x\n * " . implode("\n * ", $tags) . "\n */";
        $ensures = static fn(string $condition): string => "#[\\Pront\\Attribute\\Ensures({$condition})]";

        yield 'return type' => [
            $doc('@return int<min, 99>') . ' #[Verify(seed: 1)] public static function m(int $x): int { return $x; }',
            FailureKind::ReturnType,
        ];
        yield 'undeclared exception' => [
            $doc('@throws \DomainException') . ' #[Verify(seed: 1)] public static function m(int $x): void { if ($x >= 100) { throw new \RangeException("{$x}"); } }',
            FailureKind::UndeclaredThrowable,
        ];
        yield 'error without @throws, through a helper' => [
            'private static function check(int $x): void { if ($x >= 100) { intdiv($x, 0); } } '
                . $doc() . ' #[Verify(seed: 1)] public static function m(int $x): void { self::check($x); }',
            FailureKind::UndeclaredThrowable,
        ];
        yield 'ensures false' => [
            $doc() . ' #[Verify(seed: 1)] ' . $ensures('static function (int $return): bool { return $return < 100; }')
                . ' public static function m(int $x): int { return $x; }',
            FailureKind::EnsuresFalse,
        ];
        yield 'ensures threw' => [
            $doc() . ' #[Verify(seed: 1)] ' . $ensures('static function (int $x): bool { if ($x >= 100) { throw new \LogicException("{$x}"); } return true; }')
                . ' public static function m(int $x): void {}',
            FailureKind::EnsuresThrew,
        ];
        yield 'ensures not bool' => [
            $doc() . ' #[Verify(seed: 1)] ' . $ensures('static function (int $return) { return $return < 100 ? true : $return; }')
                . ' public static function m(int $x): int { return $x; }',
            FailureKind::EnsuresNotBool,
        ];
        yield 'unverifiable' => [
            $doc('@return int|string') . ' #[Verify(seed: 1)] ' . $ensures('static function (int $return): bool { return true; }')
                . ' public static function m(int $x): int|string { return $x >= 100 ? "big" : $x; }',
            FailureKind::Unverifiable,
        ];
        yield 'ensures on an instance method' => [
            'public int $last = 0; ' . $doc() . ' #[Verify(seed: 1)] ' . $ensures('static function (self $self): bool { return $self->last < 100; }')
                . ' public function m(int $x): void { $this->last = $x; }',
            FailureKind::EnsuresFalse,
        ];
    }

    #[DataProvider('failures')]
    public function testAnOracleFailureIsShrunkReRunAndReplayed(string $members, FailureKind $kind): void
    {
        [$project, $discovery] = Harness::discover(['src/A.php' => Harness::php("final class A { {$members} }")]);
        $this->journal = Journal::start($project->directory, static fn() => null);
        $engine = new Engine($this->journal, new Oracle());
        $target = $discovery->targets[0];

        $report = $engine->verify($target, self::RUN_SEED);

        self::assertSame(TargetStatus::Failed, $report->status);
        $counterexample = $report->counterexample;
        self::assertNotNull($counterexample);
        self::assertSame($kind, FailureKind::of($counterexample->failure), (string) $counterexample->failure->signature);
        self::assertGreaterThan(0, $counterexample->shrink->accepted, 'the failure keeps its signature between the fresh trial and shrink candidates');
        self::assertSame([5, 5], [$counterexample->reruns, $counterexample->reproduced]);
        self::assertSame(100, $target->arguments->generate(ChoiceBuffer::repairing($counterexample->buffer))['x']);

        $replay = $engine->replay($counterexample->token, $discovery);
        self::assertTrue($replay->outcome->reproduces($counterexample->failure));
    }

    public function testATargetThatHoldsEveryOraclePasses(): void
    {
        [$project, $discovery] = Harness::discover(['src/A.php' => Harness::php(<<<'PHP'
            final class A {
                /**
                 * @param list<int<0, 100>> $xs
                 * @return int<0, max>
                 * @throws \DomainException
                 */
                #[Verify]
                #[\Pront\Attribute\Ensures(static function (int $return, array $xs): bool { return $return === array_sum($xs); })]
                public static function m(array $xs): int
                {
                    if (count($xs) > 5) {
                        throw new \DomainException('declared');
                    }

                    return array_sum($xs);
                }
            }
            PHP)]);
        $this->journal = Journal::start($project->directory, static fn() => null);
        $engine = new Engine($this->journal, new Oracle());

        $report = $engine->verify($discovery->targets[0], self::RUN_SEED);

        self::assertSame(TargetStatus::Passed, $report->status, (string) $report->counterexample?->failure->signature);
        self::assertSame(100, $report->passed);
    }
}
