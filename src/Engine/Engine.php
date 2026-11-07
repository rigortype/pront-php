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
 * constructor) is a failure. Both are classified with the engine's own frames above the call
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

    /** @var \Closure(Target, Input): Outcome */
    private readonly \Closure $property;

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
            $outcome = $this->evaluate($target, $trial);
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
     * runs each trial and sends back its `Outcome` with `Generator::send()`, or the `Rejected` the
     * property threw for a discard; it must not keep the trial's input (see `Trial`).
     *
     * Each fresh trial is one attempt: `begin` is written before its input is generated, `end` after
     * the outcome was sent and the input released. A discard (while generating, or sent back)
     * counts toward `k` but not toward the iterations; exceeding the discard threshold stops the
     * iterator with `TrialsStatus::TooManyDiscards`. A failure stops it with `TrialsStatus::Failed`;
     * a failure while generating is not yielded. The return value is the `TrialsResult`.
     *
     * @return \Generator<int, Trial, Outcome|Rejected, TrialsResult>
     */
    public function trials(Target $target, int $seed): \Generator
    {
        $name = $target->name();
        $discards = new Discards($target->verify->iterations);
        $passed = 0;
        for ($k = 1; $passed < $target->verify->iterations; $k++) {
            $buffer = ChoiceBuffer::fresh(Prng::forTrial($seed, $k));
            $outcome = null;
            $rejection = null;
            $n = $this->journal->begin(AttemptKind::Fresh, $name, $seed, $k, null);
            try {
                $input = $this->generateInput($target, $buffer);
                if ($input instanceof Outcome) {
                    $outcome = $input;
                } else {
                    $trial = new Trial($name, $seed, $k, $input, $buffer->consumed());
                    unset($input);
                    $sent = yield $k => $trial;
                    $trial->release();
                    unset($trial);
                    if ($sent instanceof Rejected) {
                        $rejection = $sent;
                        $outcome = Outcome::discarded();
                    } elseif ($sent instanceof Outcome && $sent->kind !== OutcomeKind::Invalid) {
                        $outcome = $sent;
                    } else {
                        throw new \LogicException('Send the trial\'s Outcome (passed, discarded or failed) or the Rejected to the trial iterator');
                    }
                }
            } catch (Rejected $rejected) {
                $rejection = $rejected;
                $outcome = Outcome::discarded();
            } finally {
                $this->journal->end($n, $outcome);
            }

            if ($outcome->kind === OutcomeKind::Passed) {
                $passed++;
            } elseif ($outcome->kind === OutcomeKind::Discarded) {
                try {
                    $discards->record($rejection ?? new Rejected('the property discarded the input'));
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
        $shrink = $shrinker->shrink($failing, $failure, fn(ChoiceBuffer $buffer): Outcome => $this->attempt($target, $buffer));

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
     * One journaled attempt that is not a fresh trial of the iterator.
     *
     * @param list<Draw>|null $recording the buffer the attempt runs, null to regenerate from (seed, k)
     */
    private function recorded(AttemptKind $kind, Target $target, int $seed, int $k, ?array $recording, ChoiceBuffer $buffer): Outcome
    {
        $outcome = null;
        $n = $this->journal->begin($kind, $target->name(), $seed, $k, $recording);
        try {
            $outcome = $this->attempt($target, $buffer);
        } catch (Rejected) {
            $outcome = Outcome::discarded();
        } catch (InvalidChoice) {
            $outcome = Outcome::invalid();
        } finally {
            $this->journal->end($n, $outcome);
        }

        return $outcome;
    }

    /**
     * Generates the input from `$buffer` and runs the property on it; the input is released when
     * this returns. `Rejected` and `InvalidChoice` propagate (the shrinker classifies them).
     */
    private function attempt(Target $target, ChoiceBuffer $buffer): Outcome
    {
        $input = $this->generateInput($target, $buffer);

        return $input instanceof Outcome ? $input : $this->runProperty($target, $input);
    }

    /**
     * @return Outcome|Rejected the property's outcome, or its rejection (a discard)
     */
    private function evaluate(Target $target, Trial $trial): Outcome|Rejected
    {
        try {
            return $this->runProperty($target, $trial->input());
        } catch (Rejected $rejected) {
            return $rejected;
        }
    }

    /**
     * The receiver first (an instance method), then the parameters in declaration order; a failure
     * of user code reached while generating is returned as the failing `Outcome`.
     *
     * @throws Rejected
     * @throws InvalidChoice
     */
    private function generateInput(Target $target, ChoiceBuffer $buffer): Input|Outcome
    {
        try {
            $receiver = $target->receiver?->generate($buffer);
            $arguments = $target->arguments->generate($buffer);
        } catch (Rejected | InvalidChoice $expected) {
            throw $expected;
        } catch (\Throwable $failure) {
            return Outcome::fromThrowable($failure, self::outerFrames($failure));
        }
        if ($receiver !== null && !is_object($receiver)) {
            throw new \LogicException("The receiver generator of {$target->name()} made no object");
        }

        return new Input($receiver, $arguments);
    }

    /**
     * @throws Rejected
     */
    private function runProperty(Target $target, Input $input): Outcome
    {
        try {
            return ($this->property)($target, $input);
        } catch (Rejected $rejected) {
            throw $rejected;
        } catch (\Throwable $failure) {
            return Outcome::fromThrowable($failure, self::outerFrames($failure));
        }
    }

    /**
     * The number of outermost stack frames of `$failure` that lie above the engine's call into user
     * code (`generateInput()` or `runProperty()`): they differ between a fresh trial and a shrink
     * candidate, so they are not part of the failure signature.
     *
     * @return int<0, max>
     */
    private static function outerFrames(\Throwable $failure): int
    {
        $trace = $failure->getTrace();
        foreach ($trace as $index => $frame) {
            if (($frame['class'] ?? null) === self::class && in_array($frame['function'], ['generateInput', 'runProperty'], true)) {
                return count($trace) - $index;
            }
        }

        return 0;
    }
}
