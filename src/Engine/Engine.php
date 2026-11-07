<?php

declare(strict_types=1);

namespace Pront\Engine;

use Pront\ChoiceBuffer\ChoiceBuffer;
use Pront\ChoiceBuffer\Draw;
use Pront\ChoiceBuffer\InvalidChoice;
use Pront\ChoiceBuffer\Prng;
use Pront\Discovery\DiscoveryResult;
use Pront\Discovery\Target;
use Pront\Generator\Defaults;
use Pront\Generator\Discards;
use Pront\Generator\Rejected;
use Pront\Generator\TooManyDiscards;
use Pront\Journal\Journal;
use Pront\Shrinker\AttemptObserver;
use Pront\Shrinker\Outcome;
use Pront\Shrinker\OutcomeKind;
use Pront\Shrinker\Shrinker;

/**
 * The Stage 1 engine (ADR-0004): runs trials in-process against verified targets, journals every
 * attempt, shrinks a failure and re-runs the counterexample to detect flakiness.
 *
 * The property is pluggable: `$property` runs the target for one input and returns its `Outcome`
 * (the oracles fill it). A `Rejected` it throws is a discard; any other throwable it lets escape
 * is a failure classified by `Outcome::fromThrowable()`. Generating the input follows the same
 * rule: `Rejected` is a discard, any other throwable from user code reached while generating (a
 * constructor) is a failure; so is a throwable from releasing the input (a destructor). Both are classified with the engine's own frames above the call
 * dropped, so a fresh trial and a shrink candidate that fail the same way have the same signature.
 *
 * Code under test that ends the process (`exit`, `die`), registers its own shutdown functions or
 * is declared `never` is undefined behaviour (ADR-0004 item 7); nothing here detects it.
 */
final class Engine
{
    /** The replay-limits note (ADR-0004 item 9), reported when a replay does not fail. */
    public const string REPLAY_LIMITS = 'The replay did not fail. A replay token reproduces the Pront-side inputs of one attempt only: '
        . 'static or global state that earlier trials of the original run left behind is not reproduced, '
        . 'so a failure that depends on such state may not reproduce.';

    /**
     * How many throwables `drop()` frees in a row before it gives up: a destructor that throws a
     * throwable owning another such object (`throw new E(new self())`) never stops.
     */
    public const int DROP_LIMIT = 100;

    /** @var list<\Throwable> throwables `drop()` gave up on; kept alive so freeing them throws nothing */
    private static array $unfreeable = [];

    /** @var \Closure(Target, Input): Outcome */
    private readonly \Closure $property;

    /**
     * An `InvalidChoice` that a failure of the code under test (a throwing destructor while
     * freeing a partly generated input) turned into a failing outcome; on a fresh buffer it is a
     * Pront bug, rethrown once the attempt has ended.
     */
    private ?InvalidChoice $invalidBehindFailure = null;

    /**
     * @param callable(Target, Input): Outcome $property
     * @param ?\Closure(): float $clock monotonic seconds for the shrink wall-time budget
     */
    public function __construct(
        private readonly Journal $journal,
        callable $property,
        private readonly int $flakyRuns = Defaults::FLAKY_RUNS,
        private readonly int $maxShrinkEvaluations = Shrinker::MAX_SHRINK_STEPS,
        private readonly float $maxShrinkSeconds = Shrinker::MAX_SHRINK_SECONDS,
        private readonly ?\Closure $clock = null,
    ) {
        if ($flakyRuns < 1) {
            throw new \InvalidArgumentException('The flaky re-run count must be at least 1');
        }
        $this->property = $property(...);
    }

    /**
     * The seed of a target: `#[Verify(seed:)]` when set, otherwise derived from the run seed and the
     * target name (ADR-0004 item 4).
     */
    public static function targetSeed(Target $target, int $runSeed): int
    {
        return $target->verify->seed ?? Prng::targetSeed($runSeed, $target->name());
    }

    /**
     * Verifies every target of `$discovery`, in order.
     */
    public function run(DiscoveryResult $discovery, int $runSeed): RunReport
    {
        $reports = [];
        foreach ($discovery->targets as $target) {
            $reports[] = $this->verify($target, $runSeed);
        }

        return new RunReport($runSeed, $reports);
    }

    /**
     * The verify-and-shrink loop of one target: its fresh trials until `iterations` passed, a
     * failure or too many discards; a failure is shrunk and its counterexample re-run
     * `$flakyRuns` times.
     */
    public function verify(Target $target, int $runSeed): TargetReport
    {
        $seed = self::targetSeed($target, $runSeed);
        $trials = $this->trials($target, $seed);
        $trial = $trials->current();
        while ($trial !== null) {
            $outcome = $this->runProperty($target, $trial->input());
            $trial = null;
            $trials->send($outcome);
            $trial = $trials->current();
        }
        $result = $trials->getReturn();
        $name = $target->name();
        if ($result->status === TrialsStatus::Completed) {
            return new TargetReport($name, $seed, TargetStatus::Passed, $result->trials, $result->passed, $result->discards);
        }
        if ($result->status === TrialsStatus::TooManyDiscards) {
            return new TargetReport($name, $seed, TargetStatus::TooManyDiscards, $result->trials, $result->passed, $result->discards, message: $result->message);
        }
        if ($result->failingBuffer === null || $result->failure === null) {
            throw new \LogicException('A failed trial result carries its buffer and failure');
        }
        $counterexample = $this->counterexample($target, $seed, $result->trials, $result->failingBuffer, $result->failure);

        return new TargetReport(
            $name,
            $seed,
            $counterexample->flaky() ? TargetStatus::Flaky : TargetStatus::Failed,
            $result->trials,
            $result->passed,
            $result->discards,
            $counterexample,
        );
    }

    /**
     * The trial iterator (ADR-0004 item 1): yields fresh trial `k` (from 1) of `$target`, generated
     * from its own stream `Prng::forTrial($seed, k)`, until `iterations` trials passed. The caller
     * runs each trial and sends back its `Outcome` with `Generator::send()` (a discard as
     * `Outcome::discarded()` with the rejection's message, never the `Rejected` itself: its stack
     * trace holds the input); it must not keep the trial's input (see `Trial`).
     *
     * Each fresh trial is one attempt: `begin` is written before its input is generated, `end` after
     * the outcome was sent and the input released; a throwable from releasing the input (a
     * destructor) fails the trial unless it already failed. A discard (while generating, or sent back)
     * counts toward `k` but not toward the iterations; exceeding the discard threshold stops the
     * iterator with `TrialsStatus::TooManyDiscards`. A failure stops it with `TrialsStatus::Failed`;
     * a failure while generating is not yielded. The return value is the `TrialsResult`.
     *
     * @return \Generator<int, Trial, Outcome, TrialsResult>
     */
    public function trials(Target $target, int $seed): \Generator
    {
        $name = $target->name();
        $discards = new Discards($target->verify->iterations);
        $passed = 0;
        for ($k = 1; $passed < $target->verify->iterations; $k++) {
            $buffer = ChoiceBuffer::fresh(Prng::forTrial($seed, $k));
            $outcome = null;
            $n = $this->journal->begin(AttemptKind::Fresh, $name, $seed, $k, null);
            try {
                $input = $this->generateInput($target, $buffer);
                if ($input instanceof Outcome) {
                    $outcome = $input;
                } else {
                    $trial = new Trial($name, $seed, $k, $input, $buffer->consumed());
                    unset($input);
                    $sent = yield $k => $trial;
                    $valid = $sent instanceof Outcome && $sent->kind !== OutcomeKind::Invalid;
                    $released = $this->releaseInput($trial, $valid ? $sent : Outcome::passed());
                    unset($trial, $sent);
                    if (!$valid) {
                        throw new \LogicException('Send the trial\'s Outcome (passed, discarded or failed) to the trial iterator');
                    }
                    $outcome = $released;
                }
            } finally {
                if (isset($trial)) {
                    // The iterator was abandoned (or failed) while the trial was out: release its
                    // input inside the attempt, before `end`.
                    $this->releaseInput($trial, Outcome::passed());
                    unset($trial);
                }
                $this->journal->end($n, $outcome);
            }
            $this->rethrowInvalidBehindFailure();

            if ($outcome->kind === OutcomeKind::Passed) {
                $passed++;
            } elseif ($outcome->kind === OutcomeKind::Discarded) {
                try {
                    $discards->record(new Rejected($outcome->reason ?? 'the property discarded the input'));
                } catch (TooManyDiscards $tooMany) {
                    return new TrialsResult(TrialsStatus::TooManyDiscards, $k, $passed, $discards->count(), message: $tooMany->getMessage());
                }
            } else {
                return new TrialsResult(TrialsStatus::Failed, $k, $passed, $discards->count(), $buffer->consumed(), $outcome);
            }
        }

        return new TrialsResult(TrialsStatus::Completed, $k - 1, $passed, $discards->count());
    }

    /**
     * Re-runs the attempt a replay token names (ADR-0004 item 8) as one journaled attempt: a token
     * with a buffer replays it through `ChoiceBuffer::repairing()`, one without regenerates fresh
     * trial k from (seed, k). The note says what a passing replay cannot rule out (item 9).
     *
     * @throws InvalidReplayToken when the token names no verified target of `$discovery`
     */
    public function replay(ReplayToken $token, DiscoveryResult $discovery): ReplayResult
    {
        $target = null;
        foreach ($discovery->targets as $candidate) {
            if ($candidate->name() === $token->target) {
                $target = $candidate;
                break;
            }
        }
        if ($target === null) {
            throw new InvalidReplayToken("The replay token names {$token->target}, which is not a verified target of this run");
        }
        $buffer = $token->buffer === null
            ? ChoiceBuffer::fresh(Prng::forTrial($token->seed, $token->k))
            : ChoiceBuffer::repairing($token->buffer);
        $outcome = $this->recorded(AttemptKind::Replay, $target, $token->seed, $token->k, $token->buffer, $buffer);

        return new ReplayResult($token, $outcome, $outcome->kind === OutcomeKind::Failed ? null : self::REPLAY_LIMITS);
    }

    /**
     * Shrinks the failure of fresh trial `$k` and re-runs the minimal buffer `$flakyRuns` times.
     * Every candidate and every re-run is an attempt with its own `begin` and `end`.
     *
     * @param list<Draw> $failing
     */
    private function counterexample(Target $target, int $seed, int $k, array $failing, Outcome $failure): Counterexample
    {
        $observer = new class ($this->journal, $target->name(), $seed, $k) implements AttemptObserver {
            private int $n = 0;

            public function __construct(
                private readonly Journal $journal,
                private readonly string $target,
                private readonly int $seed,
                private readonly int $k,
            ) {
            }

            public function begin(array $buffer): void
            {
                $this->n = $this->journal->begin(AttemptKind::Shrink, $this->target, $this->seed, $this->k, $buffer);
            }

            public function end(array $buffer, ?Outcome $outcome): void
            {
                $this->journal->end($this->n, $outcome);
            }
        };
        $shrinker = new Shrinker($this->maxShrinkEvaluations, $this->maxShrinkSeconds, $observer, $this->clock);
        $shrink = $shrinker->shrink($failing, $failure, fn(ChoiceBuffer $buffer): Outcome => $this->attempt($target, $seed, $k, $buffer));

        $reproduced = 0;
        for ($run = 0; $run < $this->flakyRuns; $run++) {
            $outcome = $this->recorded(AttemptKind::Flaky, $target, $seed, $k, $shrink->buffer, ChoiceBuffer::repairing($shrink->buffer));
            if ($outcome->reproduces($failure)) {
                $reproduced++;
            }
        }

        return new Counterexample(
            $k,
            $shrink->buffer,
            $failure,
            new ReplayToken($target->name(), $seed, $k, $shrink->buffer),
            $shrink,
            $this->flakyRuns,
            $reproduced,
        );
    }

    /**
     * One journaled attempt that is not a fresh trial of the iterator. An `InvalidChoice` makes a
     * recorded buffer an invalid candidate; on a fresh buffer (`$recording` null) it is a Pront bug
     * and propagates, as in the trial iterator.
     *
     * @param list<Draw>|null $recording the buffer the attempt runs, null to regenerate from (seed, k)
     */
    private function recorded(AttemptKind $kind, Target $target, int $seed, int $k, ?array $recording, ChoiceBuffer $buffer): Outcome
    {
        $outcome = null;
        $n = $this->journal->begin($kind, $target->name(), $seed, $k, $recording);
        try {
            $outcome = $this->attempt($target, $seed, $k, $buffer);
        } catch (InvalidChoice $invalid) {
            if ($recording === null) {
                throw $invalid;
            }
            $outcome = Outcome::invalid();
        } finally {
            $this->journal->end($n, $outcome);
        }
        if ($recording === null) {
            $this->rethrowInvalidBehindFailure();
        }

        return $outcome;
    }

    /**
     * Rethrows an `InvalidChoice` of a fresh buffer that a failure of the code under test hid
     * (see `$invalidBehindFailure`), so the Pront bug is not lost; the attempt has ended with the
     * failure in the journal.
     *
     * @throws InvalidChoice
     */
    private function rethrowInvalidBehindFailure(): void
    {
        $invalid = $this->invalidBehindFailure;
        $this->invalidBehindFailure = null;
        if ($invalid !== null) {
            throw $invalid;
        }
    }

    /**
     * Generates the input from `$buffer`, runs the property on it and releases the input, the way
     * the trial iterator does. `InvalidChoice` propagates (the shrinker classifies it).
     */
    private function attempt(Target $target, int $seed, int $k, ChoiceBuffer $buffer): Outcome
    {
        $input = $this->generateInput($target, $buffer);
        if ($input instanceof Outcome) {
            return $input;
        }
        $trial = new Trial($target->name(), $seed, $k, $input, $buffer->consumed());
        unset($input);

        return $this->releaseInput($trial, $this->runProperty($target, $trial->input()));
    }

    /**
     * Releases the trial's input. A throwable from user code run by the release (a destructor)
     * fails the attempt, unless it already failed with its own signature.
     */
    private function releaseInput(Trial $trial, Outcome $outcome): Outcome
    {
        try {
            $trial->release();
            self::collectCycles();

            return $outcome;
        } catch (\Throwable $caught) {
            $outcome = self::failedBy($outcome, $caught);
        }

        return self::drop($caught, $outcome);
    }

    /**
     * Frees `$value` (a caught throwable, a partly generated value) inside the attempt, then
     * collects cycles. Freeing can run user destructors, which can throw a throwable that owns
     * further user objects, and so on: each such throwable is caught and freed in turn, until a
     * free completes, so no throwable ever leaves the engine or outlives the attempt. The first one
     * fails the attempt, unless it already failed with its own signature. After `DROP_LIMIT` frees
     * in a row it gives up: the last throwable is kept alive and the attempt fails with a signature
     * naming the limit.
     */
    private static function drop(mixed &$value, Outcome $outcome): Outcome
    {
        for ($freed = 0; $freed < self::DROP_LIMIT; $freed++) {
            try {
                $value = null;
                self::collectCycles();

                return $outcome;
            } catch (\Throwable $next) {
                $outcome = self::failedBy($outcome, $next);
                $value = $next;
                unset($next);
            }
        }
        // Freeing kept throwing: keep the last throwable alive for the rest of the process rather
        // than free it outside the attempt, and fail the attempt with a signature that says so.
        // Such destructors never terminate: PHP's own shutdown frees what is kept here and runs
        // the chain again (code that never terminates stops the run, ADR-0004 Consequences); the
        // failure is reported and the journal is done before that.
        self::$unfreeable[] = $value;
        $last = Outcome::fromThrowable($value, self::outerFrames($value));
        $value = null;

        return Outcome::failed(sprintf(
            'Freeing the input threw %d times in a row (destructors that keep throwing; the last throwable is never freed): %s',
            self::DROP_LIMIT,
            $last->signature,
        ));
    }

    /**
     * `$outcome` if it already failed, otherwise the failure `$throwable` signs.
     */
    private static function failedBy(Outcome $outcome, \Throwable $throwable): Outcome
    {
        return $outcome->kind === OutcomeKind::Failed ? $outcome : Outcome::fromThrowable($throwable, self::outerFrames($throwable));
    }

    /**
     * Frees values the release left in reference cycles now, inside the attempt, rather than at
     * an arbitrary later point of the run (ADR-0004 item 5: `end` follows the release). It frees
     * every collectable cycle, so a throwing destructor of cyclic garbage left before the attempt
     * is reported as a failure of this attempt (reported, never silent; a re-run tells it apart as
     * flaky). Its cost grows with the live graph while roots are buffered.
     */
    private static function collectCycles(): void
    {
        if (gc_status()['roots'] > 0) {
            gc_collect_cycles();
        }
    }

    /**
     * The receiver first (an instance method), then the parameters in declaration order. A
     * `Rejected` is returned as a discard with its message (the exception itself is dropped here:
     * its trace may hold generated values); a failure of user code reached while generating is
     * returned as the failing `Outcome`. The caught throwable and what was generated before the
     * stop are freed here (`drop()`).
     *
     * @throws InvalidChoice
     */
    private function generateInput(Target $target, ChoiceBuffer $buffer): Input|Outcome
    {
        $this->invalidBehindFailure = null;
        $receiver = null;
        $invalid = null;
        $caught = null;
        try {
            $receiver = $target->receiver?->generate($buffer);
            $arguments = $target->arguments->generate($buffer);
        } catch (InvalidChoice $stop) {
            $invalid = $stop;
            $outcome = Outcome::invalid();
        } catch (Rejected $caught) {
            $outcome = Outcome::discarded($caught->getMessage());
        } catch (\Throwable $caught) {
            $outcome = Outcome::fromThrowable($caught, self::outerFrames($caught));
        }
        if (isset($outcome)) {
            // Free the caught throwable (it may own user objects), then what was generated before
            // the stop (the receiver), each on its own, inside the attempt; a throwable from
            // freeing either is a failure of the code under test, even on an invalid candidate.
            unset($stop);
            $outcome = self::drop($caught, $outcome);
            $outcome = self::drop($receiver, $outcome);
            if ($invalid !== null) {
                if ($outcome->kind === OutcomeKind::Invalid) {
                    throw $invalid;
                }
                $this->invalidBehindFailure = $invalid;
            }

            return $outcome;
        }
        if ($receiver !== null && !is_object($receiver)) {
            throw new \LogicException("The receiver generator of {$target->name()} made no object");
        }

        return new Input($receiver, $arguments);
    }

    /**
     * Runs the property. A `Rejected` it throws is returned as a discard with its message, and the
     * exception is freed here (`drop()`), before the input is released: its trace holds the input.
     */
    private function runProperty(Target $target, Input $input): Outcome
    {
        try {
            return ($this->property)($target, $input);
        } catch (Rejected $caught) {
            $outcome = Outcome::discarded($caught->getMessage());
        } catch (\Throwable $caught) {
            $outcome = Outcome::fromThrowable($caught, self::outerFrames($caught));
        }

        return self::drop($caught, $outcome);
    }

    /**
     * The number of outermost stack frames of `$failure` that lie above the engine's call into user
     * code (`generateInput()`, `runProperty()` or `releaseInput()`): they differ between a fresh trial and a shrink
     * candidate, so they are not part of the failure signature.
     *
     * @return int<0, max>
     */
    private static function outerFrames(\Throwable $failure): int
    {
        $trace = $failure->getTrace();
        foreach ($trace as $index => $frame) {
            if (($frame['class'] ?? null) === self::class && in_array($frame['function'], ['generateInput', 'runProperty', 'releaseInput'], true)) {
                return count($trace) - $index;
            }
        }

        return 0;
    }
}
