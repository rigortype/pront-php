<?php

declare(strict_types=1);

namespace Pront\Tests\Shrinker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pront\ChoiceBuffer\ChoiceBuffer;
use Pront\ChoiceBuffer\Draw;
use Pront\ChoiceBuffer\DrawKind;
use Pront\ChoiceBuffer\FloatCodec;
use Pront\ChoiceBuffer\InvalidChoice;
use Pront\ChoiceBuffer\IntOrder;
use Pront\ChoiceBuffer\Prng;
use Pront\ChoiceBuffer\Simplicity;
use Pront\Generator\Generator;
use Pront\Generator\GeneratorFactory;
use Pront\Generator\Rejected;
use Pront\Shrinker\AttemptObserver;
use Pront\Shrinker\Outcome;
use Pront\Shrinker\OutcomeKind;
use Pront\Shrinker\Shrinker;
use Pront\Shrinker\ShrinkResult;
use Pront\Shrinker\StopReason;
use Pront\Type\ArrayShapeType;
use Pront\Type\BoolType;
use Pront\Type\FloatType;
use Pront\Type\IntType;
use Pront\Type\ListType;
use Pront\Type\NullType;
use Pront\Type\StringType;
use Pront\Type\Type;
use Pront\Type\UnionType;

final class ShrinkerTest extends TestCase
{
    /** Failing fresh starts shrunk per case. */
    private const int STARTS = 5;

    private const int SEED = 19;

    /**
     * A type, a property (true = holds), and the minimal counterexample every uniform start must
     * shrink to. The properties hold for every edge value of the type: an edge takes one draw, so a
     * failing edge would be the shortlex minimum (see `testEdgeValuesAreShortlexSmallerThanUniformValues()`).
     *
     * @return iterable<string, array{Type, \Closure(mixed): bool, mixed}>
     */
    public static function minima(): iterable
    {
        yield 'int toward 0 from above' => [new IntType(-10000, 10000), static fn(int $x): bool => $x < 100, 100];
        yield 'int toward 0 from below' => [new IntType(-10000, 10000), static fn(int $x): bool => $x > -100, -100];
        yield 'int negative flips to positive' => [new IntType(-10000, 10000), static fn(int $x): bool => abs($x) < 50, 50];
        yield 'int range lower bound' => [new IntType(10, 20), static fn(int $x): bool => false, 10];
        yield 'int negative range bound' => [new IntType(-20, -10), static fn(int $x): bool => false, -10];
        yield 'bool' => [new BoolType(), static fn(bool $b): bool => false, false];
        yield 'float toward 0' => [new FloatType(), static fn(float $f): bool => !($f >= 1.5 && $f < 1e6), 2.0];
        yield 'list minimal length' => [new ListType(new IntType()), static fn(array $l): bool => count($l) < 3, [0, 0, 0]];
        yield 'list single offending element' => [
            new ListType(new IntType(-10000, 10000)),
            static fn(array $l): bool => array_filter($l, static fn(int $x): bool => $x >= 10 && $x < 10000) === [],
            [10],
        ];
        yield 'string offending character' => [new StringType(), static fn(string $s): bool => !str_contains($s, 'b'), 'b'];
        yield 'string minimal length' => [
            new StringType(),
            static fn(string $s): bool => preg_match('/\A[a-zA-Z]{3,20}\z/', $s) !== 1,
            'aaa',
        ];
        yield 'union picks the first member' => [
            UnionType::of(new IntType(), new StringType()),
            static fn(mixed $v): bool => false,
            0,
        ];
        yield 'union shrinks within a later member' => [
            UnionType::of(new IntType(), new StringType()),
            static fn(mixed $v): bool => !is_string($v) || preg_match('/\A[b-z]+\z/', $v) !== 1,
            'b',
        ];
        yield 'nullable leaves null' => [
            UnionType::of(new IntType(-10000, 10000), new NullType()),
            static fn(?int $v): bool => $v === null || $v < 6 || $v === 10000,
            6,
        ];
        yield 'nested lists' => [
            new ListType(new ListType(new IntType(0, 9))),
            static fn(array $l): bool => array_filter($l, static fn(array $inner): bool => count($inner) >= 2) === [],
            [[0, 0]],
        ];
        yield 'shape' => [
            new ArrayShapeType(['a' => new IntType(0, 10000), 'b' => new StringType()]),
            static fn(array $s): bool => !($s['a'] >= 1000 && $s['a'] < 10000 && preg_match('/\A[b-z]+\z/', $s['b']) === 1),
            ['a' => 1000, 'b' => 'b'],
        ];
    }

    /**
     * @param \Closure(mixed): bool $property
     */
    #[DataProvider('minima')]
    public function testShrinksUniformStartsToTheMinimum(Type $type, \Closure $property, mixed $expected): void
    {
        $generator = (new GeneratorFactory(0.0))->forType($type);
        $starts = self::failingStarts($generator, $property, self::STARTS);
        self::assertCount(self::STARTS, $starts, 'not enough failing fresh trials');
        foreach ($starts as $start) {
            $result = self::shrink($generator, $property, $start);
            self::assertSame(StopReason::Fixpoint, $result->stopReason);
            self::assertSame($expected, $generator->generate(ChoiceBuffer::replay($result->slots())));
            self::assertLessThanOrEqual(0, Simplicity::compareBuffers($result->buffer, $start));
            self::assertLessThanOrEqual(Shrinker::MAX_SHRINK_STEPS, $result->evaluations);
        }
    }

    /**
     * An edge value takes one draw and a uniform value two, so the shortest failing buffer is an edge
     * when one fails (ADR-0002 section 4: edge values are minimal by design), here PHP_INT_MAX for
     * an offending list element rather than 10.
     */
    public function testEdgeValuesAreShortlexSmallerThanUniformValues(): void
    {
        $generator = (new GeneratorFactory(0.0))->forType(new ListType(new IntType()));
        $property = static fn(array $l): bool => array_filter($l, static fn(int $x): bool => $x >= 10) === [];
        foreach (self::failingStarts($generator, $property, self::STARTS) as $start) {
            $result = self::shrink($generator, $property, $start);
            self::assertSame([PHP_INT_MAX], $generator->generate(ChoiceBuffer::replay($result->slots())));
        }
    }

    /**
     * The selector is tried at every lower value, not only its neighbour: from 2^32 (selector 11)
     * the neighbour 2^32 - 1 passes, but PHP_INT_MAX (selector 5) fails.
     */
    public function testSelectorIsTriedAtEveryLowerValue(): void
    {
        $generator = (new GeneratorFactory())->forType(new IntType());
        $start = self::consume($generator, [[11]]);
        self::assertSame(2 ** 32, $generator->generate(ChoiceBuffer::replay(self::slotsOf($start))));
        $property = static fn(int $x): bool => $x !== PHP_INT_MAX && $x !== 2 ** 32;

        $result = self::shrink($generator, $property, $start);

        self::assertSame([[5]], $result->slots());
        self::assertSame(StopReason::Fixpoint, $result->stopReason);
    }

    /**
     * The repaired joint edit: lowering a length leaves a dependent slot out of its new range; the
     * repair clamps it and the candidate still fails. The failure needs both at the same value, so
     * neither slot can be lowered alone: without the repair the start is already a fixpoint.
     */
    public function testRepairClampsDependentSlotsAfterLowering(): void
    {
        $replay = static function (ChoiceBuffer $buffer): Outcome {
            $n = $buffer->drawLength(0, 10);
            $i = $buffer->drawInt(0, $n);

            return $i >= 3 && $i === $n ? Outcome::failed('i >= 3') : Outcome::passed();
        };
        $start = [new Draw(DrawKind::Length, [9]), new Draw(DrawKind::Int, [9, 0])];

        $result = (new Shrinker())->shrink($start, Outcome::failed('i >= 3'), $replay);

        self::assertSame([[3], [3, 0]], $result->slots());
        self::assertSame(StopReason::Fixpoint, $result->stopReason);
    }

    /**
     * A float of kind 1 or 2 is tried at its integer-valued kind 0 neighbour, then lowered.
     *
     * @return iterable<string, array{float}>
     */
    public static function floatStarts(): iterable
    {
        yield 'kind 1' => [3.7];
        yield 'kind 1 huge' => [1e300];
        yield 'kind 2' => [INF];
    }

    #[DataProvider('floatStarts')]
    public function testFloatsMoveToKindZero(float $value): void
    {
        $generator = (new GeneratorFactory())->forType(new FloatType());
        $start = self::consume($generator, [(new FloatCodec())->encode($value)]);
        self::assertSame($value, $generator->generate(ChoiceBuffer::replay(self::slotsOf($start))));
        $property = static fn(float $f): bool => !($f >= 1.5);

        $result = self::shrink($generator, $property, $start);

        self::assertSame(2.0, $generator->generate(ChoiceBuffer::replay($result->slots())));
        self::assertSame(StopReason::Fixpoint, $result->stopReason);
    }

    /**
     * Negative floats move to the positive value: kind 0 by the int side rule, kind 1 by its sign
     * slot (1e300 has no kind 0 neighbour that fails).
     *
     * @return iterable<string, array{float, \Closure(float): bool, float}>
     */
    public static function negativeFloats(): iterable
    {
        yield 'kind 0' => [-5.0, static fn(float $f): bool => abs($f) < 1.5, 2.0];
        yield 'kind 1' => [-1e300, static fn(float $f): bool => abs($f) < 1e20, 1e20];
    }

    /**
     * @param \Closure(float): bool $property
     */
    #[DataProvider('negativeFloats')]
    public function testNegativeFloatsBecomePositive(float $value, \Closure $property, float $atLeast): void
    {
        $generator = (new GeneratorFactory())->forType(new FloatType());
        $start = self::consume($generator, [(new FloatCodec())->encode($value)]);

        $result = self::shrink($generator, $property, $start);

        $shrunk = $generator->generate(ChoiceBuffer::replay($result->slots()));
        self::assertGreaterThanOrEqual($atLeast, $shrunk);
        self::assertLessThan(2 * $atLeast, $shrunk);
    }

    /**
     * A candidate whose consumed buffer equals the best (deleting a trailing all-zero draw reads it
     * back as zeros) is not strictly smaller and is not accepted.
     */
    public function testAnEqualBufferIsNotAccepted(): void
    {
        $replay = static function (ChoiceBuffer $buffer): Outcome {
            $first = $buffer->drawInt(0, 10);
            $buffer->drawInt(0, 10);

            return $first === 5 ? Outcome::failed('five') : Outcome::passed();
        };
        $start = [new Draw(DrawKind::Int, [5, 0]), new Draw(DrawKind::Int, [0, 0])];

        $result = (new Shrinker())->shrink($start, Outcome::failed('five'), $replay);

        self::assertSame(0, $result->accepted);
        self::assertSame(StopReason::Fixpoint, $result->stopReason);
        self::assertSame([[5, 0], [0, 0]], $result->slots());
    }

    /**
     * A recording evaluated in an earlier round is not run again. Pinned on a small fixture: the
     * second round repeats the first round's deletion and lowering to 0, which cost nothing.
     */
    public function testARecordingIsEvaluatedOnce(): void
    {
        $observer = new RecordingObserver();
        $replay = static fn(ChoiceBuffer $b): Outcome => $b->drawInt(0, 1000) >= 50 ? Outcome::failed('big') : Outcome::passed();

        $result = (new Shrinker(observer: $observer))
            ->shrink([new Draw(DrawKind::Int, [700, 0])], Outcome::failed('big'), $replay);

        $keys = array_map(static fn(array $buffer): string => serialize($buffer), $observer->begun);
        self::assertSame(array_unique($keys), $keys);
        self::assertSame(15, $result->evaluations);
    }

    /**
     * A candidate that still fails but whose consumed buffer is longer is never accepted.
     */
    public function testNeverAcceptsABufferThatIsNotShortlexSmaller(): void
    {
        $replay = static function (ChoiceBuffer $buffer): Outcome {
            $n = $buffer->drawLength(0, 10);
            for ($i = $n; $i < 10; $i++) {
                $buffer->drawBool();
            }

            return Outcome::failed('always');
        };
        $start = [new Draw(DrawKind::Length, [10])];

        $result = (new Shrinker())->shrink($start, Outcome::failed('always'), $replay);

        self::assertSame([[10]], $result->slots());
        self::assertSame(0, $result->accepted);
        self::assertGreaterThan(0, $result->evaluations);
        self::assertSame(StopReason::Fixpoint, $result->stopReason);
    }

    /**
     * @return iterable<string, array{\Closure(int): Outcome}>
     */
    public static function notReproducing(): iterable
    {
        yield 'passes' => [static fn(int $x): Outcome => Outcome::passed()];
        yield 'other failure' => [static fn(int $x): Outcome => Outcome::failed('other')];
        yield 'discarded' => [static fn(int $x): Outcome => Outcome::discarded()];
        yield 'rejected' => [static fn(int $x): Outcome => throw new Rejected('too small')];
        yield 'invalid choice' => [static fn(int $x): Outcome => throw new InvalidChoice('bad slot')];
    }

    /**
     * Below 50 the candidate does something other than reproduce the failure; it is never accepted.
     *
     * @param \Closure(int): Outcome $below
     */
    #[DataProvider('notReproducing')]
    public function testOnlyTheSameFailureIsAccepted(\Closure $below): void
    {
        $replay = static function (ChoiceBuffer $buffer) use ($below): Outcome {
            $x = $buffer->drawInt(0, 1000);

            return $x >= 50 ? Outcome::failed('big') : $below($x);
        };
        $start = [new Draw(DrawKind::Int, [700, 0])];

        $result = (new Shrinker())->shrink($start, Outcome::failed('big'), $replay);

        self::assertSame([[50, 0]], $result->slots());
    }

    public function testDeterministic(): void
    {
        $generator = (new GeneratorFactory())->forType(new ListType(new StringType()));
        $property = static fn(array $l): bool => count($l) < 2 || implode('', $l) === '';
        [$start] = self::failingStarts($generator, $property, 1);

        $first = self::shrink($generator, $property, $start);
        $second = self::shrink($generator, $property, $start);

        self::assertEquals($first, $second);
    }

    public function testEvaluationBudgetIsHonoured(): void
    {
        $generator = (new GeneratorFactory(0.0))->forType(new ListType(new IntType()));
        $property = static fn(array $l): bool => count($l) < 3;
        [$start] = self::failingStarts($generator, $property, 1);
        $observer = new RecordingObserver();

        $result = self::shrink($generator, $property, $start, new Shrinker(maxEvaluations: 5, observer: $observer));

        self::assertSame(StopReason::Budget, $result->stopReason);
        self::assertSame(5, $result->evaluations);
        self::assertCount(5, $observer->begun);
    }

    public function testZeroBudgetReturnsTheInput(): void
    {
        $start = [new Draw(DrawKind::Int, [700, 0])];
        $replay = static fn(ChoiceBuffer $b): Outcome => self::fail('no evaluation expected');

        $result = (new Shrinker(maxEvaluations: 0))->shrink($start, Outcome::failed('f'), $replay);

        self::assertSame(StopReason::Budget, $result->stopReason);
        self::assertSame(0, $result->evaluations);
        self::assertSame($start, $result->buffer);
    }

    public function testWallTimeIsCheckedBetweenEvaluations(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $replay = static function (ChoiceBuffer $buffer) use (&$now): Outcome {
            $now += 1.0;

            return $buffer->drawInt(0, 1000) >= 50 ? Outcome::failed('big') : Outcome::passed();
        };

        $result = (new Shrinker(maxSeconds: 3.0, clock: $clock))
            ->shrink([new Draw(DrawKind::Int, [700, 0])], Outcome::failed('big'), $replay);

        self::assertSame(StopReason::Time, $result->stopReason);
        self::assertSame(3, $result->evaluations);
    }

    public function testEveryEvaluationIsAnAttempt(): void
    {
        $observer = new RecordingObserver();
        $replay = static fn(ChoiceBuffer $b): Outcome => $b->drawInt(0, 1000) >= 50 ? Outcome::failed('big') : Outcome::passed();

        $result = (new Shrinker(observer: $observer))
            ->shrink([new Draw(DrawKind::Int, [700, 0])], Outcome::failed('big'), $replay);

        self::assertCount($result->evaluations, $observer->begun);
        self::assertSame($observer->begun, array_column($observer->ended, 0));
        foreach ($observer->ended as [$buffer, $outcome]) {
            self::assertInstanceOf(Outcome::class, $outcome);
        }
        self::assertContains([[50, 0]], array_map(self::slotsOf(...), $observer->begun));
    }

    public function testUnclassifiedExceptionsEndTheAttemptAndPropagate(): void
    {
        $observer = new RecordingObserver();
        $replay = static fn(ChoiceBuffer $b): Outcome => throw new \LogicException('engine bug');

        try {
            (new Shrinker(observer: $observer))
                ->shrink([new Draw(DrawKind::Int, [700, 0])], Outcome::failed('big'), $replay);
            self::fail('the exception must propagate');
        } catch (\LogicException $e) {
            self::assertSame('engine bug', $e->getMessage());
        }
        self::assertCount(1, $observer->begun);
        self::assertSame([[$observer->begun[0], null]], $observer->ended);
    }

    public function testOnlyAFailureCanBeShrunk(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Shrinker())->shrink([], Outcome::passed(), static fn(ChoiceBuffer $b): Outcome => Outcome::passed());
    }

    public function testFailureSignatureIncludesTheCallStack(): void
    {
        $check = static fn(bool $ok): \Throwable => new \LogicException('failed');
        $viaA = static fn(): \Throwable => $check(false);
        $viaB = static fn(): \Throwable => $check(false);

        self::assertFalse(Outcome::fromThrowable($viaA())->reproduces(Outcome::fromThrowable($viaB())));
        $first = null;
        foreach ([1, 2] as $i) {
            $outcome = Outcome::fromThrowable($viaA());
            $first ??= $outcome;
            self::assertTrue($outcome->reproduces($first));
        }
    }

    public function testFailureSignatureIsTheThrowSite(): void
    {
        $throw = static fn(string $message): \Throwable => new \DomainException($message);
        [$a, $b] = array_map(static fn(string $m): Outcome => Outcome::fromThrowable($throw($m)), ['input 1', 'input 2']);
        $other = Outcome::fromThrowable(new \DomainException('input 1'));
        $otherClass = Outcome::fromThrowable(new \RangeException('input 1'));

        self::assertSame(OutcomeKind::Failed, $a->kind);
        self::assertTrue($b->reproduces($a));
        self::assertFalse($other->reproduces($a));
        self::assertFalse($otherClass->reproduces($other));
        self::assertFalse(Outcome::passed()->reproduces($a));
    }

    /**
     * Consumed buffers of the first `$count` fresh trials whose input violates `$property`.
     *
     * @param \Closure(mixed): bool $property
     * @return list<list<Draw>>
     */
    private static function failingStarts(Generator $generator, \Closure $property, int $count): array
    {
        $starts = [];
        for ($k = 1; $k <= 2000 && count($starts) < $count; $k++) {
            $buffer = ChoiceBuffer::fresh(Prng::forTrial(self::SEED, $k));
            if (!$property($generator->generate($buffer))) {
                $starts[] = $buffer->consumed();
            }
        }

        return $starts;
    }

    /**
     * @param \Closure(mixed): bool $property
     * @param list<Draw> $start
     */
    private static function shrink(Generator $generator, \Closure $property, array $start, Shrinker $shrinker = new Shrinker()): ShrinkResult
    {
        $replay = static fn(ChoiceBuffer $buffer): Outcome => $property($generator->generate($buffer))
            ? Outcome::passed()
            : Outcome::failed('property');

        return $shrinker->shrink($start, Outcome::failed('property'), $replay);
    }

    /**
     * @param list<list<int>> $slots
     * @return list<Draw>
     */
    private static function consume(Generator $generator, array $slots): array
    {
        $buffer = ChoiceBuffer::replay($slots);
        $generator->generate($buffer);

        return $buffer->consumed();
    }

    /**
     * @param list<Draw> $draws
     * @return list<list<int>>
     */
    private static function slotsOf(array $draws): array
    {
        return array_map(static fn(Draw $draw): array => $draw->slots, $draws);
    }
}

/**
 * Records every attempt the shrinker reports.
 */
final class RecordingObserver implements AttemptObserver
{
    /** @var list<list<Draw>> */
    public array $begun = [];

    /** @var list<array{list<Draw>, ?Outcome}> */
    public array $ended = [];

    public function begin(array $buffer): void
    {
        $this->begun[] = $buffer;
    }

    public function end(array $buffer, ?Outcome $outcome): void
    {
        $this->ended[] = [$buffer, $outcome];
    }
}
