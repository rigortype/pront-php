<?php

declare(strict_types=1);

namespace Pront\Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pront\ChoiceBuffer\ChoiceBuffer;
use Pront\ChoiceBuffer\InvalidChoice;
use Pront\ChoiceBuffer\Prng;
use Pront\Discovery\DiscoveryResult;
use Pront\Discovery\Target;
use Pront\Engine\AttemptKind;
use Pront\Engine\Engine;
use Pront\Engine\Input;
use Pront\Engine\InvalidReplayToken;
use Pront\Engine\ReplayToken;
use Pront\Engine\TargetReport;
use Pront\Engine\TargetStatus;
use Pront\Engine\Trial;
use Pront\Engine\TrialsResult;
use Pront\Engine\TrialsStatus;
use Pront\Generator\ArgumentsGenerator;
use Pront\Generator\Defaults;
use Pront\Generator\Generator;
use Pront\Generator\Rejected;
use Pront\Journal\Journal;
use Pront\Shrinker\Outcome;
use Pront\Shrinker\OutcomeKind;
use Pront\Tests\Discovery\Project;

final class EngineTest extends TestCase
{
    private const int RUN_SEED = 21;

    /** @var list<Journal> journals to finish after the test */
    private array $journals = [];

    protected function tearDown(): void
    {
        foreach ($this->journals as $journal) {
            $journal->finish();
        }
        $this->journals = [];
    }

    private function journal(Project $project): Journal
    {
        $journal = Journal::start($project->directory, static fn() => null);
        $this->journals[] = $journal;

        return $journal;
    }

    /**
     * @param array<string, string> $files
     * @param ?callable(Target, Input): Outcome $property
     * @return array{Engine, DiscoveryResult, Journal}
     */
    private function engine(array $files, ?callable $property = null, int $flakyRuns = Defaults::FLAKY_RUNS): array
    {
        [$project, $discovery] = Harness::discover($files);
        $journal = $this->journal($project);

        return [new Engine($journal, $property ?? Harness::call(...), $flakyRuns), $discovery, $journal];
    }

    /**
     * @return array<string, int> attempt kind => begin records of that kind
     */
    private static function attemptCounts(Journal $journal): array
    {
        $counts = [];
        $open = [];
        foreach (Harness::records($journal->file) as $record) {
            if ($record['type'] === 'begin') {
                self::assertArrayNotHasKey($record['n'], $open, 'attempts do not overlap');
                $open[$record['n']] = true;
                $counts[$record['attempt']] = ($counts[$record['attempt']] ?? 0) + 1;
            } elseif ($record['type'] === 'end') {
                self::assertArrayHasKey($record['n'], $open);
                unset($open[$record['n']]);
            }
        }
        self::assertSame([], $open, 'every attempt ended');

        return $counts;
    }

    public function testEveryIterationPassesAndEachFreshTrialIsOneAttempt(): void
    {
        [$engine, $discovery, $journal] = $this->engine(['src/A.php' => Harness::php(
            'final class A { #[Verify(iterations: 7)] public static function m(int $x, string $s): void {} }',
        )]);

        $report = $engine->verify($discovery->targets[0], self::RUN_SEED);

        self::assertSame(TargetStatus::Passed, $report->status);
        self::assertSame([7, 7, 0], [$report->trials, $report->passed, $report->discards]);
        self::assertNull($report->counterexample);
        self::assertSame(['fresh' => 7], self::attemptCounts($journal));
        $begins = array_values(array_filter(Harness::records($journal->file), static fn(array $r): bool => $r['type'] === 'begin'));
        self::assertSame(range(1, 7), array_column($begins, 'k'));
        self::assertSame(array_fill(0, 7, null), array_column($begins, 'buffer'));
        self::assertSame(array_fill(0, 7, $report->seed), array_column($begins, 'seed'));
    }

    /**
     * @return iterable<string, array{?int, int, int, bool}> Verify seed, run seed A, run seed B, same target seed
     */
    public static function targetSeeds(): iterable
    {
        yield 'derived seeds differ by run seed' => [null, 1, 2, false];
        yield 'derived seed repeats for the same run seed' => [null, 5, 5, true];
        yield 'Verify(seed:) ignores the run seed' => [77, 1, 2, true];
    }

    #[DataProvider('targetSeeds')]
    public function testTargetSeed(?int $verifySeed, int $runSeedA, int $runSeedB, bool $same): void
    {
        $seed = $verifySeed === null ? '' : "seed: {$verifySeed}";
        [, $discovery] = Harness::discover(['src/A.php' => Harness::php("final class A { #[Verify({$seed})] public static function m(int \$x): void {} }")]);
        $target = $discovery->targets[0];

        $a = Engine::targetSeed($target, $runSeedA);
        self::assertSame($same, $a === Engine::targetSeed($target, $runSeedB));
        self::assertSame($verifySeed ?? Prng::targetSeed($runSeedA, $target->name()), $a);
    }

    public function testEachFreshTrialIsRegeneratedFromSeedAndKAlone(): void
    {
        [$engine, $discovery] = $this->engine(['src/A.php' => Harness::php(
            'final class A { #[Verify(iterations: 12)] /** @param list<string> $l */ public static function m(int $x, array $l, ?float $f): void {} }',
        )]);
        $target = $discovery->targets[0];
        $seed = Engine::targetSeed($target, self::RUN_SEED);

        $run = static function () use ($engine, $target, $seed): array {
            $buffers = [];
            $trials = $engine->trials($target, $seed);
            for ($trial = $trials->current(); $trial !== null; $trial = $trials->current()) {
                $buffers[$trial->k] = $trial->buffer;
                $trial = null;
                $trials->send(Outcome::passed());
            }

            return $buffers;
        };
        $first = $run();
        self::assertSame(range(1, 12), array_keys($first));
        self::assertEquals($first, $run(), 'the same seed yields the same trials');

        foreach ($first as $k => $buffer) {
            $alone = ChoiceBuffer::fresh(Prng::forTrial($seed, $k));
            $target->arguments->generate($alone);
            self::assertEquals($alone->consumed(), $buffer, "trial {$k} is regenerated from (seed, k) alone");
        }
        self::assertNotEquals($first[1], $first[2]);
    }

    public function testOneTargetsShrinkingDoesNotChangeAnotherTargetsInputs(): void
    {
        $files = ['src/A.php' => Harness::php(
            'final class A {
                #[Verify(iterations: 30)] public static function fails(int $x): void { if ($x > 3) { throw new \DomainException("x"); } }
                #[Verify(iterations: 30)] public static function other(int $y, string $s): void {}
            }',
        )];
        $seen = [];
        $recording = static function (Target $target, Input $input) use (&$seen): Outcome {
            if ($target->method->getName() === 'other') {
                $seen[] = $input->arguments;
            }

            return Harness::call($target, $input);
        };
        [$engine, $discovery] = $this->engine($files, $recording);

        $reports = $engine->run($discovery, self::RUN_SEED)->targets;
        self::assertSame([TargetStatus::Failed, TargetStatus::Passed], array_map(static fn(TargetReport $r) => $r->status, $reports));
        self::assertGreaterThan(0, $reports[0]->counterexample?->shrink->evaluations);
        $withShrinking = $seen;

        $seen = [];
        $engine->verify($discovery->targets[1], self::RUN_SEED);
        self::assertSame($withShrinking, $seen);
    }

    /**
     * Failing targets, and the minimal counterexample of `$x` the engine must find (every edge value
     * passes, since a failing edge would be the shortlex minimum, ADR-0002 section 4). Each
     * fails through a different path: an exception from the method, an `\Error` from a receiver's
     * constructor (a failure, not a discard), and an Outcome the property returns.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function failures(): iterable
    {
        yield 'method throws' => [
            'final class A { /** @param int<-10000, 10000> $x */ #[Verify] public static function m(int $x): void { if ($x >= 100 && $x < 10000) { throw new \DomainException("too big: {$x}"); } } }',
            100,
        ];
        yield 'deep throw through a helper' => [
            'final class A {
                private static function check(int $x): void { if ($x <= -40 && $x > -10000) { throw new \RangeException("{$x}"); } }
                /** @param int<-10000, 10000> $x */ #[Verify] public static function m(int $x): void { self::check($x); }
            }',
            -40,
        ];
        yield 'constructor error while generating' => [
            'final class A {
                /** @param int<0, 2000> $x */
                public function __construct(public int $x) { if ($x > 50 && $x < 2000) { throw new \ArithmeticError("{$x}"); } }
                #[Verify] public function m(): void {}
            }',
            51,
        ];
    }

    #[DataProvider('failures')]
    public function testAFailureIsShrunkToItsMinimalCounterexampleAndReRunFiveTimes(string $code, int $minimal): void
    {
        [$engine, $discovery, $journal] = $this->engine(['src/A.php' => Harness::php($code)]);
        $target = $discovery->targets[0];

        $report = $engine->verify($target, self::RUN_SEED);

        self::assertSame(TargetStatus::Failed, $report->status);
        $counterexample = $report->counterexample;
        self::assertNotNull($counterexample);
        self::assertSame(OutcomeKind::Failed, $counterexample->failure->kind);
        self::assertGreaterThan(0, $counterexample->shrink->accepted, 'a failure keeps its signature between fresh trials and shrink candidates');
        self::assertSame([5, 5], [$counterexample->reruns, $counterexample->reproduced]);
        self::assertSame($report->trials, $counterexample->k);
        $x = $target->receiver === null
            ? $target->arguments->generate(ChoiceBuffer::repairing($counterexample->buffer))['x']
            : self::receiverX($target, $counterexample->buffer);
        self::assertSame($minimal, $x);
        self::assertSame(
            ['fresh' => $report->trials, 'shrink' => $counterexample->shrink->evaluations, 'flaky' => 5],
            self::attemptCounts($journal),
        );
        self::assertEquals(new ReplayToken($target->name(), $report->seed, $counterexample->k, $counterexample->buffer), $counterexample->token);
    }

    /**
     * @param list<\Pront\ChoiceBuffer\Draw> $buffer
     */
    private static function receiverX(Target $target, array $buffer): int
    {
        try {
            $receiver = $target->receiver?->generate(ChoiceBuffer::repairing($buffer));
        } catch (\ArithmeticError $error) {
            return (int) $error->getMessage();
        }

        throw new \LogicException('The counterexample did not fail: ' . var_export($receiver, true));
    }

    public function testShrinkCandidatesAndFlakyReRunsAreJournaledWithTheirBufferKinds(): void
    {
        [$engine, $discovery, $journal] = $this->engine(['src/A.php' => Harness::php(
            'final class A { #[Verify] public static function m(bool $b, int $x): void { if ($x > 9) { throw new \DomainException(); } } }',
        )]);
        $report = $engine->verify($discovery->targets[0], self::RUN_SEED);
        $counterexample = $report->counterexample;
        self::assertNotNull($counterexample);

        $flaky = array_values(array_filter(Harness::records($journal->file), static fn(array $r): bool => ($r['attempt'] ?? null) === 'flaky'));
        self::assertCount(5, $flaky);
        foreach ($flaky as $record) {
            self::assertSame(ReplayToken::encodeBuffer($counterexample->buffer), $record['buffer']);
            self::assertSame($counterexample->k, $record['k']);
            self::assertSame($counterexample->token->toString(), $record['token']);
        }
        foreach (Harness::records($journal->file) as $record) {
            if (($record['attempt'] ?? null) === 'shrink') {
                $token = ReplayToken::parse($record['token']);
                self::assertSame($record['buffer'], ReplayToken::encodeBuffer($token->buffer ?? []));
                self::assertContains($record['buffer'][0][0] ?? 'Selector', ['Selector', 'Bool', 'Int']);
            }
        }
    }

    /**
     * @return iterable<string, array{list<bool>, TargetStatus, int}> failing calls (true = fail), status, reproduced
     */
    public static function flakiness(): iterable
    {
        yield 'deterministic failure' => [[true], TargetStatus::Failed, 5];
        yield 'fails once only' => [[true, false], TargetStatus::Flaky, 0];
    }

    /**
     * @param list<bool> $pattern calls of the property that fail, repeating its last entry
     */
    #[DataProvider('flakiness')]
    public function testTheCounterexampleIsReportedFlakyWhenAReRunDoesNotReproduceIt(array $pattern, TargetStatus $status, int $reproduced): void
    {
        $calls = 0;
        $property = static function (Target $target, Input $input) use (&$calls, $pattern): Outcome {
            $fails = $pattern[min($calls++, count($pattern) - 1)];

            return $fails ? Outcome::failed('flaky property') : Outcome::passed();
        };
        [$engine, $discovery] = $this->engine(['src/A.php' => Harness::php('final class A { #[Verify] public static function m(int $x): void {} }')], $property);

        $report = $engine->verify($discovery->targets[0], self::RUN_SEED);

        self::assertSame($status, $report->status);
        self::assertSame($reproduced, $report->counterexample?->reproduced);
        self::assertSame($status === TargetStatus::Flaky, $report->counterexample->flaky());
    }

    /**
     * @return iterable<string, array{string, ?callable(Target, Input): Outcome, int, int}> code, property, passed, discards
     */
    public static function discards(): iterable
    {
        yield 'constructor rejects odd values' => [
            'final class A {
                public function __construct(int $n) { if ($n % 2 !== 0) { throw new \InvalidArgumentException("odd"); } }
                #[Verify(iterations: 20)] public function m(): void {}
            }',
            null,
            20,
        ];
        yield 'property rejects odd values' => [
            'final class A { #[Verify(iterations: 20)] public static function m(int $n): void {} }',
            static fn(Target $t, Input $in): Outcome => $in->arguments['n'] % 2 !== 0 ? throw new Rejected('odd') : Outcome::passed(),
            20,
        ];
        yield 'property returns a discard' => [
            'final class A { #[Verify(iterations: 20)] public static function m(int $n): void {} }',
            static fn(Target $t, Input $in): Outcome => $in->arguments['n'] % 2 !== 0 ? Outcome::discarded() : Outcome::passed(),
            20,
        ];
    }

    /**
     * @param ?callable(Target, Input): Outcome $property
     */
    #[DataProvider('discards')]
    public function testADiscardCountsTowardKButNotTowardTheIterations(string $code, ?callable $property, int $iterations): void
    {
        [$engine, $discovery, $journal] = $this->engine(['src/A.php' => Harness::php($code)], $property);

        $report = $engine->verify($discovery->targets[0], self::RUN_SEED);

        self::assertSame(TargetStatus::Passed, $report->status);
        self::assertSame($iterations, $report->passed);
        self::assertGreaterThan(0, $report->discards);
        self::assertSame($report->passed + $report->discards, $report->trials);
        self::assertSame(['fresh' => $report->trials], self::attemptCounts($journal));
    }

    /**
     * @return iterable<string, array{string, ?callable(Target, Input): Outcome, string}>
     */
    public static function tooManyDiscards(): iterable
    {
        yield 'constructor always rejects' => [
            'final class A { public function __construct() { throw new \InvalidArgumentException("never"); } #[Verify(iterations: 3)] public function m(): void {} }',
            null,
            'most frequent reason (31 times): {NS}\A::__construct() threw InvalidArgumentException: never',
        ];
        yield 'property always rejects' => [
            'final class A { #[Verify(iterations: 3)] public static function m(): void {} }',
            static fn(Target $t, Input $in): Outcome => throw new Rejected('assumption'),
            'most frequent reason (31 times): assumption',
        ];
    }

    /**
     * @param ?callable(Target, Input): Outcome $property
     */
    #[DataProvider('tooManyDiscards')]
    public function testTooManyDiscardsIsAConfigurationErrorNotAPass(string $code, ?callable $property, string $reason): void
    {
        [$engine, $discovery] = $this->engine(['src/A.php' => Harness::php($code)], $property);

        $report = $engine->verify($discovery->targets[0], self::RUN_SEED);

        self::assertSame(TargetStatus::TooManyDiscards, $report->status);
        self::assertSame([31, 0, 31], [$report->trials, $report->passed, $report->discards]);
        self::assertStringContainsString('31 candidates were discarded, more than the threshold of 30', (string) $report->message);
        self::assertStringEndsWith(str_replace('{NS}', $discovery->targets[0]->method->getDeclaringClass()->getNamespaceName(), $reason), (string) $report->message);
    }

    /**
     * @return iterable<string, array{string, int, ?callable(Target, Input): Outcome, bool}> method body, attempts, property, cyclic input
     */
    public static function inputLifetimes(): iterable
    {
        yield 'fresh trials' => ['', 3, null, false];
        yield 'a fresh trial, then flaky re-runs' => ['throw new \\DomainException();', 6, null, false];
        // The Rejected's trace holds the input; it must not outlive the attempt.
        yield 'discards' => ['', 31, static fn(Target $t, Input $in): Outcome => throw new Rejected('never'), false];
        // Only the cycle collector frees an input in a reference cycle.
        yield 'an input in a reference cycle' => ['', 3, null, true];
    }

    /**
     * @param ?callable(Target, Input): Outcome $property
     */
    #[DataProvider('inputLifetimes')]
    public function testEachInputIsBuiltAfterItsBeginAndDestroyedBeforeItsEnd(string $body, int $attempts, ?callable $property, bool $cycle): void
    {
        [$project, $discovery] = Harness::discover(['src/A.php' => Harness::php(
            'final class A {
                public static ?\Closure $probe = null;
                public ?self $self = null;
                public function __construct() { ' . ($cycle ? '$this->self = $this; ' : '') . '(self::$probe)?->__invoke("built"); }
                public function __destruct() { (self::$probe)?->__invoke("destroyed"); }
                #[Verify(iterations: 3)] public function m(): void { ' . $body . ' }
            }',
        )]);
        $journal = $this->journal($project);
        $class = $discovery->targets[0]->method->getDeclaringClass()->getName();
        $events = [];
        $class::$probe = static function (string $event) use ($journal, &$events): void {
            $types = array_count_values(array_column(Harness::records($journal->file), 'type')) + ['begin' => 0, 'end' => 0];
            $events[] = [$event, $types['begin'], $types['end']];
        };

        (new Engine($journal, $property ?? Harness::call(...)))->verify($discovery->targets[0], self::RUN_SEED);
        $class::$probe = null;

        $expected = [];
        for ($i = 1; $i <= $attempts; $i++) {
            $expected[] = ['built', $i, $i - 1];
            $expected[] = ['destroyed', $i, $i - 1];
        }
        self::assertSame($expected, $events, 'each input is built after its attempt\'s begin and destroyed before its end');
    }

    /**
     * @return iterable<string, array{string, string}> method body, signature site
     */
    public static function destructorFailures(): iterable
    {
        yield 'a passing trial' => ['', 'DomainException'];
        yield 'a failing trial keeps its own failure' => ['throw new \\RangeException();', 'RangeException'];
    }

    #[DataProvider('destructorFailures')]
    public function testAThrowingDestructorOfTheInputFailsTheAttempt(string $body, string $exception): void
    {
        [$engine, $discovery, $journal] = $this->engine(['src/A.php' => Harness::php(
            'final class A {
                public function __destruct() { throw new \\DomainException("dtor"); }
                #[Verify] public function m(): void { ' . $body . ' }
            }',
        )]);

        $report = $engine->verify($discovery->targets[0], self::RUN_SEED);

        self::assertSame(TargetStatus::Failed, $report->status);
        self::assertSame(1, $report->trials);
        self::assertStringStartsWith($exception . '@', (string) $report->counterexample?->failure->signature);
        self::assertSame(5, $report->counterexample->reproduced, 'the release fails the same way on every path');
        self::assertSame(['fresh' => 1, 'flaky' => 5], self::attemptCounts($journal));
    }

    /**
     * `$target` with parameter `$x` drawn by `$generator`.
     */
    private static function withArgument(Target $target, Generator $generator): Target
    {
        return new Target($target->method, $target->verify, $target->signature, new ArgumentsGenerator(['x' => $generator]), $target->receiver, $target->conditions, $target->reservedNameConflicts);
    }

    /**
     * @return iterable<string, array{\Throwable, bool, string}> the stop, receiver in a reference cycle, signature class (a
     *     failure keeps its own signature)
     */
    public static function generationStops(): iterable
    {
        foreach ([false, true] as $cycle) {
            $suffix = $cycle ? ', receiver in a cycle' : '';
            yield "an invalid buffer{$suffix}" => [new InvalidChoice('stop'), $cycle, 'DomainException'];
            yield "a rejection{$suffix}" => [new Rejected('stop'), $cycle, 'DomainException'];
            yield "a failure{$suffix}" => [new \RangeException('stop'), $cycle, 'RangeException'];
        }
    }

    #[DataProvider('generationStops')]
    public function testAThrowingDestructorOfAPartlyGeneratedInputFailsTheAttempt(\Throwable $stop, bool $cycle, string $class): void
    {
        [$engine, $discovery, $journal] = $this->engine(['src/A.php' => Harness::php(
            'final class A {
                public ?self $self = null;
                public function __construct() { ' . ($cycle ? '$this->self = $this;' : '') . ' }
                public function __destruct() { throw new \\DomainException("dtor"); }
                #[Verify] public function m(int $x): void {}
            }',
        )]);
        $stopping = new class ($stop) implements Generator {
            public function __construct(private \Throwable $stop) {}

            public function generate(ChoiceBuffer $buffer): mixed
            {
                throw $this->stop;
            }
        };
        $target = self::withArgument($discovery->targets[0], $stopping);

        $result = $engine->replay(new ReplayToken($target->name(), 1, 1, []), new DiscoveryResult([$target], [], []));

        self::assertSame(OutcomeKind::Failed, $result->outcome->kind);
        self::assertStringStartsWith($class . '@', (string) $result->outcome->signature);
        self::assertSame('failed', Harness::records($journal->file)[1]['outcome']);
    }

    /**
     * Throwables that own a user object whose destructor throws, on every path the engine catches
     * one: a constructor `\Exception` (wrapped as `Rejected`), a constructor `\Error`, a property
     * `Rejected` and a failure of the method. Freeing the caught throwable must not escape the engine.
     *
     * @return iterable<string, array{string, string, bool, string}> constructor body, method body, property rejects, signature class
     */
    public static function owningThrowables(): iterable
    {
        yield 'constructor exception (a discard)' => ['throw new E(new Res());', '', false, 'DomainException'];
        yield 'constructor error (a failure)' => ['throw new F(new Res());', '', false, '{NS}\\F'];
        yield 'property rejection' => ['', '', true, 'DomainException'];
        yield 'method failure' => ['', 'throw new E(new Res());', false, '{NS}\\E'];
        // Freeing the caught throwable throws a throwable that owns another throwing destructor.
        yield 'constructor exception, nested' => ['throw new E(new Res2());', '', false, '{NS}\\E'];
        yield 'method failure, nested' => ['', 'throw new E(new Res2());', false, '{NS}\\E'];
    }

    #[DataProvider('owningThrowables')]
    public function testFreeingACaughtThrowableThatOwnsAThrowingDestructorFailsTheAttempt(string $constructor, string $method, bool $rejects, string $class): void
    {
        $property = $rejects
            ? static function (Target $target, Input $input): Outcome {
                $namespace = $target->method->getDeclaringClass()->getNamespaceName();

                throw new Rejected('assumption', previous: new ($namespace . '\\E')(new ($namespace . '\\Res')()));
            }
            : null;
        [$engine, $discovery, $journal] = $this->engine(['src/A.php' => Harness::php(
            'final class Res { public function __destruct() { throw new \\DomainException("res"); } }
             final class Res2 { public function __destruct() { throw new E(new Res()); } }
             final class E extends \\Exception { public function __construct(public object $res) { parent::__construct("e"); } }
             final class F extends \\Error { public function __construct(public object $res) { parent::__construct("f"); } }
             final class A {
                public function __construct() { ' . $constructor . ' }
                #[Verify] public function m(): void { ' . $method . ' }
            }',
        )], $property);
        $target = $discovery->targets[0];
        $class = str_replace('{NS}', $target->method->getDeclaringClass()->getNamespaceName(), $class);

        $report = $engine->verify($target, self::RUN_SEED);
        $replay = $engine->replay(new ReplayToken($target->name(), $report->seed, 1, []), $discovery);

        self::assertSame(TargetStatus::Failed, $report->status);
        self::assertSame(5, $report->counterexample?->reproduced);
        foreach ([$report->counterexample->failure, $replay->outcome] as $outcome) {
            self::assertStringStartsWith($class . '@', (string) $outcome->signature);
        }
        self::assertNotContains('error', array_column(array_filter(Harness::records($journal->file), static fn(array $r): bool => $r['type'] === 'end'), 'outcome'));
    }

    public function testAThrowingStopAndAThrowingReceiverAreBothFreedInsideTheAttempt(): void
    {
        [$engine, $discovery, $journal] = $this->engine(['src/A.php' => Harness::php(
            'final class Res { public static int $freed = 0; public function __destruct() { self::$freed++; throw new \\DomainException("res"); } }
             final class A {
                public static int $freed = 0;
                public function __destruct() { self::$freed++; throw new \\LogicException("receiver"); }
                #[Verify] public function m(int $x): void {}
            }',
        )]);
        $namespace = $discovery->targets[0]->method->getDeclaringClass()->getNamespaceName();
        $stopping = new class ($namespace) implements Generator {
            public function __construct(private string $namespace) {}

            public function generate(ChoiceBuffer $buffer): mixed
            {
                $owner = new \ArrayObject([new ($this->namespace . '\\Res')()]);

                throw new Rejected('stop', previous: new \RuntimeException('owner', previous: new class ($owner) extends \Exception {
                    public function __construct(public \ArrayObject $owner) { parent::__construct('owns'); }
                }));
            }
        };
        $target = self::withArgument($discovery->targets[0], $stopping);

        $result = $engine->replay(new ReplayToken($target->name(), 1, 1, []), new DiscoveryResult([$target], [], []));

        self::assertSame(OutcomeKind::Failed, $result->outcome->kind);
        self::assertStringStartsWith('DomainException@', (string) $result->outcome->signature, 'the first throwable freed fails the attempt');
        self::assertSame([1, 1], [($namespace . '\\Res')::$freed, ($namespace . '\\A')::$freed], 'the receiver is still freed');
        self::assertSame('failed', Harness::records($journal->file)[1]['outcome']);
    }

    /**
     * @return iterable<string, array{string, string, string}> extra classes, method body, signature prefix
     */
    public static function throwingReleases(): iterable
    {
        // Releasing the input throws a throwable that owns another throwing destructor.
        yield 'nested release of the input' => [
            'final class Holder { public function __destruct() { throw new E(new Res()); } }',
            '',
            '{NS}\\E@',
        ];
    }

    #[DataProvider('throwingReleases')]
    public function testThrowingReleasesFailTheAttemptWithoutLeavingTheEngine(string $classes, string $method, string $signature): void
    {
        [$engine, $discovery, $journal] = $this->engine(['src/A.php' => Harness::php(
            'final class Res { public function __destruct() { throw new \\DomainException("res"); } }
             final class E extends \\Exception { public function __construct(public object $owned) { parent::__construct("e"); } }
             ' . $classes . '
             final class A {
                public ?object $holder = null;
                public function __construct() { if (class_exists(Holder::class, false)) { $this->holder = new Holder(); } }
                #[Verify] public function m(): void { ' . $method . ' }
            }',
        )]);
        $target = $discovery->targets[0];

        $report = $engine->verify($target, self::RUN_SEED);

        self::assertSame(TargetStatus::Failed, $report->status);
        self::assertSame(5, $report->counterexample?->reproduced);
        self::assertStringStartsWith(str_replace('{NS}', $target->method->getDeclaringClass()->getNamespaceName(), $signature), (string) $report->counterexample->failure->signature);
        self::assertNotContains('error', array_column(array_filter(Harness::records($journal->file), static fn(array $r): bool => $r['type'] === 'end'), 'outcome'));
    }

    /**
     * Each throwable owns an object whose destructor throws another one, so freeing never ends.
     * The engine stops after `DROP_LIMIT` frees and reports the attempt as failed. The throwable it
     * keeps alive is freed by PHP's own shutdown, where the chain never ends either (code that never
     * terminates, ADR-0004), so this runs in a subprocess that is killed once it has reported.
     */
    public function testFreeingThatNeverEndsIsCappedAndReportedAsAFailure(): void
    {
        $project = new Project(['src/A.php' => Harness::php(
            'final class E extends \\Exception { public function __construct(public object $owned) { parent::__construct("e"); } }
             final class R { public function __destruct() { throw new E(new R()); } }
             final class A { #[Verify] public function m(): void { new R(); } }',
        )]);
        $script = Harness::script($project->directory, <<<'PHP'
            $report = $engine->verify($discovery->targets[0], 1);
            $journal->finish();
            echo $report->status->name, "\n", $report->counterexample?->reproduced, "\n", $report->counterexample?->failure->signature, "\n";
            echo json_encode(array_values(array_filter(array_map(static fn(string $l): ?string => json_decode($l, true)['outcome'] ?? null, file($journal->file, FILE_IGNORE_NEW_LINES))))), "\nreported\n";
            PHP);

        $stderr = $project->directory . '/stderr';
        $process = proc_open([PHP_BINARY, $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr, 'w']], $pipes);
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 60);
        $stdout = '';
        while (!str_contains($stdout, "reported\n") && ($line = fgets($pipes[1])) !== false) {
            $stdout .= $line;
        }
        proc_terminate($process, 9);
        proc_close($process);

        $lines = explode("\n", $stdout);
        self::assertSame(['Failed', '5', 'reported'], [$lines[0] ?? null, $lines[1] ?? null, $lines[4] ?? null], $stdout . file_get_contents($stderr));
        self::assertStringStartsWith('Freeing the input threw ' . Engine::DROP_LIMIT . ' times in a row', $lines[2]);
        self::assertNotContains('error', json_decode($lines[3], true));
    }

    public function testAnInvalidChoiceHiddenByAThrowingDestructorOnAFreshBufferIsRethrownAfterTheAttempt(): void
    {
        [$engine, $discovery, $journal] = $this->engine(['src/A.php' => Harness::php(
            'final class A { public function __destruct() { throw new \\DomainException("dtor"); } #[Verify] public function m(int $x): void {} }',
        )]);
        $invalid = new class implements Generator {
            public function generate(ChoiceBuffer $buffer): mixed
            {
                throw new InvalidChoice('bug');
            }
        };
        $target = self::withArgument($discovery->targets[0], $invalid);
        $targets = new DiscoveryResult([$target], [], []);

        self::assertSame(OutcomeKind::Failed, $engine->replay(new ReplayToken($target->name(), 1, 1, []), $targets)->outcome->kind, 'a recorded buffer: the failure');
        foreach ([
            'fresh replay' => static fn() => $engine->replay(new ReplayToken($target->name(), 1, 1, null), $targets),
            'trial iterator' => static fn() => $engine->verify($target, self::RUN_SEED),
        ] as $path => $run) {
            try {
                $run();
                self::fail("{$path}: expected the InvalidChoice");
            } catch (InvalidChoice $e) {
                self::assertSame('bug', $e->getMessage(), $path);
            }
        }
        self::assertSame(['failed', 'failed', 'failed'], array_column(array_filter(Harness::records($journal->file), static fn(array $r): bool => $r['type'] === 'end'), 'outcome'), 'each attempt ended before the rethrow');
    }

    public function testAnAbandonedTrialIteratorReleasesTheInputBeforeTheEndRecord(): void
    {
        [$project, $discovery] = Harness::discover(['src/A.php' => Harness::php(
            'final class A {
                public static ?\Closure $probe = null;
                public function __destruct() { (self::$probe)?->__invoke(); }
                #[Verify] public function m(): void {}
            }',
        )]);
        $journal = $this->journal($project);
        $class = $discovery->targets[0]->method->getDeclaringClass()->getName();
        $ends = [];
        $class::$probe = static function () use ($journal, &$ends): void {
            $ends[] = count(array_filter(Harness::records($journal->file), static fn(array $r): bool => $r['type'] === 'end'));
        };

        $trials = (new Engine($journal, Harness::call(...)))->trials($discovery->targets[0], 1);
        $trials->current();
        $trials = null;
        $class::$probe = null;

        self::assertSame([0], $ends, 'the input was released before the end record');
        self::assertSame(['begin', 'end'], array_column(Harness::records($journal->file), 'type'));
        self::assertSame('error', Harness::records($journal->file)[1]['outcome']);
    }

    public function testAnInvalidChoiceOnAFreshBufferIsAProntBugButInvalidOnARecordedOne(): void
    {
        [$engine, $discovery, $journal] = $this->engine(['src/A.php' => Harness::php('final class A { #[Verify] public static function m(int $x): void {} }')]);
        $invalid = new class implements Generator {
            public function generate(ChoiceBuffer $buffer): mixed
            {
                throw new InvalidChoice('bug');
            }
        };
        $target = self::withArgument($discovery->targets[0], $invalid);
        $targets = new DiscoveryResult([$target], [], []);

        self::assertSame(OutcomeKind::Invalid, $engine->replay(new ReplayToken($target->name(), 1, 1, []), $targets)->outcome->kind);
        try {
            $engine->replay(new ReplayToken($target->name(), 1, 1, null), $targets);
            self::fail('expected the InvalidChoice to propagate');
        } catch (InvalidChoice $e) {
            self::assertSame('bug', $e->getMessage());
        }
        self::assertSame(['invalid', 'error'], array_column(array_filter(Harness::records($journal->file), static fn(array $r): bool => $r['type'] === 'end'), 'outcome'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badSends(): iterable
    {
        yield 'nothing (foreach)' => [null];
        yield 'an invalid outcome' => [Outcome::invalid()];
        yield 'a Rejected' => [new Rejected('no')];
    }

    #[DataProvider('badSends')]
    public function testTheTrialIteratorNeedsAnOutcome(mixed $sent): void
    {
        [$engine, $discovery, $journal] = $this->engine(['src/A.php' => Harness::php('final class A { #[Verify] public static function m(int $x): void {} }')]);
        $trials = $engine->trials($discovery->targets[0], 1);
        $trials->current();

        try {
            $trials->send($sent);
            self::fail('expected a LogicException');
        } catch (\LogicException $e) {
            self::assertStringContainsString('Send the trial', $e->getMessage());
        }
        self::assertSame(['fresh' => 1], self::attemptCounts($journal));
    }

    public function testReplayingAFreshTrialTokenRegeneratesThatTrial(): void
    {
        $inputs = [];
        $property = static function (Target $target, Input $input) use (&$inputs): Outcome {
            $inputs[] = $input->arguments;

            return Outcome::passed();
        };
        [$engine, $discovery, $journal] = $this->engine(['src/A.php' => Harness::php(
            'final class A { #[Verify(iterations: 9)] public static function m(int $x, string $s): void {} }',
        )], $property);
        $report = $engine->verify($discovery->targets[0], self::RUN_SEED);
        $fresh = $inputs;

        foreach ([1, 4, 9] as $k) {
            $inputs = [];
            $result = $engine->replay(ReplayToken::parse((new ReplayToken($report->target, $report->seed, $k, null))->toString()), $discovery);
            self::assertSame([$fresh[$k - 1]], $inputs, "replaying trial {$k}");
            self::assertSame(OutcomeKind::Passed, $result->outcome->kind);
            self::assertSame(Engine::REPLAY_LIMITS, $result->note);
        }
        $replays = array_values(array_filter(Harness::records($journal->file), static fn(array $r): bool => ($r['attempt'] ?? null) === AttemptKind::Replay->value));
        self::assertSame([1, 4, 9], array_column($replays, 'k'));
    }

    public function testReplayingTheCounterexampleTokenReproducesTheFailureWithTheMinimalInput(): void
    {
        $inputs = [];
        $property = static function (Target $target, Input $input) use (&$inputs): Outcome {
            $inputs[] = $input->arguments;

            return Harness::call($target, $input);
        };
        [$engine, $discovery] = $this->engine(['src/A.php' => Harness::php(
            'final class A { /** @param list<int<0, 1000>> $l */ #[Verify] public static function m(array $l): void { if (array_sum($l) > 500) { throw new \\DomainException(); } } }',
        )], $property);
        $report = $engine->verify($discovery->targets[0], self::RUN_SEED);
        $counterexample = $report->counterexample;
        self::assertNotNull($counterexample);
        self::assertGreaterThan(0, $counterexample->shrink->accepted);
        $failingInput = $inputs[$counterexample->k - 1];

        $inputs = [];
        $result = $engine->replay(ReplayToken::parse($counterexample->token->toString()), $discovery);

        self::assertTrue($result->outcome->reproduces($counterexample->failure));
        self::assertNull($result->note);
        self::assertSame([$discovery->targets[0]->arguments->generate(ChoiceBuffer::repairing($counterexample->buffer))], $inputs);
        self::assertNotSame([$failingInput], $inputs, 'the minimal input, not the first failing one');
    }

    public function testReplayingATokenForAnUnknownTargetFails(): void
    {
        [$engine, $discovery] = $this->engine(['src/A.php' => Harness::php('final class A { #[Verify] public static function m(): void {} }')]);

        $this->expectException(InvalidReplayToken::class);
        $this->expectExceptionMessage('The replay token names Nope::m, which is not a verified target of this run');
        $engine->replay(new ReplayToken('Nope::m', 1, 1, null), $discovery);
    }

    public function testTheTrialIteratorReturnsItsResult(): void
    {
        [$engine, $discovery] = $this->engine(['src/A.php' => Harness::php('final class A { #[Verify(iterations: 2)] public static function m(int $x): void {} }')]);
        $trials = $engine->trials($discovery->targets[0], 3);
        $yielded = [];
        for ($trial = $trials->current(); $trial !== null; $trial = $trials->current()) {
            self::assertInstanceOf(Trial::class, $trial);
            $yielded[] = [$trials->key(), $trial->seed, $trial->input()->receiver];
            $sent = $trial->k === 1 ? Outcome::discarded('first') : Outcome::passed();
            $trial = null;
            $trials->send($sent);
        }

        self::assertSame([[1, 3, null], [2, 3, null], [3, 3, null]], $yielded);
        self::assertEquals(new TrialsResult(TrialsStatus::Completed, 3, 2, 1), $trials->getReturn());
    }
}
