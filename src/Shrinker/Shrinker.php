<?php

declare(strict_types=1);

namespace Pront\Shrinker;

use Pront\ChoiceBuffer\ChoiceBuffer;
use Pront\ChoiceBuffer\Draw;
use Pront\ChoiceBuffer\DrawKind;
use Pront\ChoiceBuffer\FloatCodec;
use Pront\ChoiceBuffer\InvalidChoice;
use Pront\ChoiceBuffer\Simplicity;
use Pront\Generator\Rejected;

/**
 * The internal shrinker (ADR-0002 sections 4 and 5, ADR-0007 section 2). It edits the failing
 * consumed buffer and re-runs the replay function on every candidate; a candidate is accepted only
 * if it reproduces the original failure (`Outcome::reproduces()`) and its consumed buffer is
 * strictly shortlex-smaller than the current best, so every accepted step strictly decreases and
 * shrinking terminates without relying on the budget.
 *
 * Passes, repeated until a full round accepts nothing (the fixpoint) or the budget is spent:
 * 1. block deletion of 8, 4, 2 and 1 consecutive draws;
 * 2. slot lowering: binary search toward 0 on monotone slots (an int or float magnitude, a length,
 *    an index, a bool, a nullable, a character position, a float edge index); the other slots only
 *    at their simpler neighbour (an int or float `side` 1 becomes the positive value at the same
 *    distance, a float `kind` 1 or 2 becomes its integer-valued `kind` 0 neighbour), except the
 *    selector, which is tried at every lower value;
 * 3. joint edit: a length lowered by one together with the deletion of 8, 4, 2 or 1 draws after it.
 *
 * Every candidate is a list of draws that keep the kind of the draw they were edited from, replayed
 * through `ChoiceBuffer::repairing()`: a later slot of the same kind that no longer fits its range
 * after an edit is clamped to it (the repaired joint edit) and the candidate is re-run; a slot that
 * cannot be repaired, or that an edit shifted onto a draw of another kind and is out of that draw's
 * range, makes the candidate invalid. Reading past the end of a
 * candidate reads zeros (replay semantics). A candidate that passes, is discarded (`Rejected`, or
 * `Outcome::discarded()`), is invalid (`InvalidChoice`) or fails differently is not accepted.
 *
 * The budget is checked between evaluations: before each candidate, a spent evaluation budget
 * stops with `StopReason::Budget` and an elapsed wall time with `StopReason::Time`. A candidate
 * whose recording was already evaluated is not run again and costs nothing.
 *
 * `StopReason::Fixpoint` is a local minimum of these passes, not the global shortlex minimum. Known
 * cases: a list or string at an edge length (the selector) never moves to a shorter uniform length,
 * because that needs one more draw; a float `kind` 2 is tried only at its kind 0 neighbour, never
 * at kind 1; the two kind 1 magnitude slots are lowered one at a time.
 */
final class Shrinker
{
    /** `maxShrinkSteps` of ADR-0007 section 2: candidate evaluations per shrink. */
    public const int MAX_SHRINK_STEPS = 1000;

    /** The wall-time safety net of ADR-0007 section 2, in seconds. */
    public const float MAX_SHRINK_SECONDS = 60.0;

    private const array BLOCK_SIZES = [8, 4, 2, 1];

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @var \Closure(ChoiceBuffer): Outcome */
    private \Closure $replay;

    /** @var list<Draw> */
    private array $best = [];

    private Outcome $failure;

    /** @var array<string, true> recordings already evaluated (or the best) */
    private array $seen = [];

    private int $evaluations = 0;

    private int $accepted = 0;

    private float $deadline = 0.0;

    /**
     * @param ?\Closure(): float $clock monotonic seconds; `hrtime()` by default
     */
    public function __construct(
        private readonly int $maxEvaluations = self::MAX_SHRINK_STEPS,
        private readonly float $maxSeconds = self::MAX_SHRINK_SECONDS,
        private readonly ?AttemptObserver $observer = null,
        ?\Closure $clock = null,
        private readonly FloatCodec $floats = new FloatCodec(),
    ) {
        if ($maxEvaluations < 0) {
            throw new \InvalidArgumentException('The evaluation budget must not be negative');
        }
        if (!($maxSeconds >= 0.0)) {
            throw new \InvalidArgumentException('The wall-time budget must not be negative');
        }
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1e9;
    }

    /**
     * Shrinks `$failing`, the consumed buffer of a failing run.
     *
     * The first evaluation replays `$failing` itself, and its outcome is the failure every candidate
     * must reproduce: a failure signature can depend on the call path (`Outcome::fromThrowable()`
     * includes the stack), so the original run's outcome, classified by another caller, is not
     * compared. When that replay does not fail, nothing is shrunk and the stop reason is
     * `StopReason::NotReproduced`.
     *
     * `$replay` regenerates the input from the buffer it is given, runs the property on it, and
     * returns the outcome; it must not keep the input once it returns (the attempt ends there,
     * ADR-0004). A `Rejected` or `InvalidChoice` it throws counts as a discard or an invalid
     * candidate; any other exception is rethrown (after `AttemptObserver::end()`), because it is
     * neither a pass nor a failure the shrinker can classify.
     *
     * @param list<Draw> $failing
     * @param callable(ChoiceBuffer): Outcome $replay
     */
    public function shrink(array $failing, callable $replay): ShrinkResult
    {
        $this->replay = $replay(...);
        $this->best = $failing;
        $this->failure = Outcome::invalid();
        $this->seen = [self::key($failing) => true];
        $this->evaluations = 0;
        $this->accepted = 0;
        $this->deadline = ($this->clock)() + $this->maxSeconds;

        try {
            [$this->failure] = $this->evaluate($failing);
            if ($this->failure->kind !== OutcomeKind::Failed) {
                return new ShrinkResult($this->best, $this->failure, $this->evaluations, 0, StopReason::NotReproduced);
            }
            do {
                $before = $this->accepted;
                $this->blockDeletion();
                $this->slotLowering();
                $this->jointEdit();
            } while ($this->accepted > $before);
            $stop = StopReason::Fixpoint;
        } catch (BudgetSpent $spent) {
            $stop = $spent->reason;
        }

        return new ShrinkResult($this->best, $this->failure, $this->evaluations, $this->accepted, $stop);
    }

    private function blockDeletion(): void
    {
        foreach (self::BLOCK_SIZES as $size) {
            for ($start = 0; $start + $size <= count($this->best);) {
                $candidate = $this->best;
                array_splice($candidate, $start, $size);
                if (!$this->try($candidate)) {
                    $start++;
                }
            }
        }
    }

    private function slotLowering(): void
    {
        for ($index = 0; $index < count($this->best); $index++) {
            $kind = $this->best[$index]->kind;
            for ($slot = 0; $slot < count($this->best[$index]->slots) && $this->best[$index]->kind === $kind; $slot++) {
                match ($kind) {
                    DrawKind::Selector => $this->lowerEach($index, $slot, $kind),
                    DrawKind::Int => $slot === 0 ? $this->lowerBinary($index, 0, $kind) : $this->flipSide($index, 0, $kind),
                    DrawKind::Float => $this->lowerFloat($index, $slot),
                    default => $this->lowerBinary($index, $slot, $kind),
                };
                if ($index >= count($this->best)) {
                    return;
                }
            }
        }
    }

    /**
     * A float draw: `kind` 1 or 2 to its kind 0 neighbour; within kind 0 the int rule on slots 1
     * and 2; within kind 1 binary search on the two magnitude slots and the sign to positive;
     * within kind 2 binary search on the edge index.
     */
    private function lowerFloat(int $index, int $slot): void
    {
        $slots = $this->best[$index]->slots;
        if ($slot === 0) {
            if ($slots[0] !== 0) {
                $this->try($this->with($index, $this->kindZeroNeighbour($slots)));
            }

            return;
        }
        match (true) {
            $slots[0] === 0 && $slot === 1 => $this->lowerBinary($index, 1, DrawKind::Float, 0),
            $slots[0] === 0 && $slot === 2 => $this->flipSide($index, 1, DrawKind::Float, 0),
            $slots[0] === 1 && $slot === 3 => $this->lowerTo($index, 3, 0),
            default => $this->lowerBinary($index, $slot, DrawKind::Float, $slots[0]),
        };
    }

    /**
     * Binary search toward 0 for the smallest value of slot `$slot` that is accepted; assumes
     * (but does not rely on, for correctness) that acceptance is monotone in the slot.
     */
    private function lowerBinary(int $index, int $slot, DrawKind $kind, ?int $floatKind = null): void
    {
        $value = $this->slotAt($index, $slot, $kind, $floatKind);
        if ($value === null || $value === 0) {
            return;
        }
        if ($this->lowerTo($index, $slot, 0)) {
            return;
        }
        $low = 0;
        $high = $value;
        while ($high - $low > 1) {
            $mid = $low + intdiv($high - $low, 2);
            if (!$this->lowerTo($index, $slot, $mid)) {
                $low = $mid;
                continue;
            }
            $now = $this->slotAt($index, $slot, $kind, $floatKind);
            if ($now === null) {
                return;
            }
            $high = min($mid, $now);
        }
    }

    /**
     * The selector: every lower value, simplest first; the first accepted one wins.
     */
    private function lowerEach(int $index, int $slot, DrawKind $kind): void
    {
        $value = $this->slotAt($index, $slot, $kind, null);
        for ($lower = 0; $value !== null && $lower < $value; $lower++) {
            if ($this->lowerTo($index, $slot, $lower)) {
                return;
            }
        }
    }

    /**
     * An int position on side 1, magnitude `m` (distance `m + 1`): its predecessor in the ordering
     * is the positive value at the same distance, magnitude `m + 1` on side 0. The magnitude is at
     * `$offset`, the side right after it.
     */
    private function flipSide(int $index, int $offset, DrawKind $kind, ?int $floatKind = null): void
    {
        if ($this->slotAt($index, $offset + 1, $kind, $floatKind) !== 1) {
            return;
        }
        $slots = $this->best[$index]->slots;
        $magnitude = $slots[$offset];
        $slots[$offset] = $magnitude === PHP_INT_MAX ? $magnitude : $magnitude + 1;
        $slots[$offset + 1] = 0;
        $this->try($this->with($index, $slots));
    }

    /**
     * Tries the current best with slot `$slot` of draw `$index` set to `$value`.
     */
    private function lowerTo(int $index, int $slot, int $value): bool
    {
        $slots = $this->best[$index]->slots;
        if ($slots[$slot] === $value) {
            return false;
        }
        $slots[$slot] = $value;

        return $this->try($this->with($index, $slots));
    }

    private function jointEdit(): void
    {
        for ($index = 0; $index < count($this->best); $index++) {
            foreach (self::BLOCK_SIZES as $size) {
                for ($start = $index + 1; $start + $size <= count($this->best);) {
                    $length = $this->slotAt($index, 0, DrawKind::Length, null);
                    if ($length === null || $length === 0) {
                        continue 3;
                    }
                    $candidate = $this->with($index, [$length - 1]) ?? [];
                    array_splice($candidate, $start, $size);
                    if (!$this->try($candidate)) {
                        $start++;
                    }
                }
            }
        }
    }

    /**
     * The integer-valued kind 0 neighbour of a kind 1 or 2 float: its value truncated toward 0 and
     * limited to the kind 0 range; `NAN` becomes 0.
     *
     * @param list<int> $slots
     * @return list<int>
     */
    private function kindZeroNeighbour(array $slots): array
    {
        try {
            $value = ChoiceBuffer::replay([$slots], $this->floats)->drawFloat();
        } catch (InvalidChoice) {
            return [0, 0, 0];
        }
        if (is_nan($value)) {
            return [0, 0, 0];
        }
        $limit = (float) FloatCodec::KIND0_LIMIT;
        $integer = $value >= $limit ? $limit : ($value <= -$limit ? -$limit : (float) (int) $value);

        return $this->floats->encode($integer === 0.0 ? 0.0 : $integer);
    }

    /**
     * Slot `$slot` of draw `$index` when that draw still is of `$kind` (and float kind `$floatKind`).
     */
    private function slotAt(int $index, int $slot, DrawKind $kind, ?int $floatKind): ?int
    {
        $draw = $this->best[$index] ?? null;
        if ($draw === null || $draw->kind !== $kind || ($floatKind !== null && $draw->slots[0] !== $floatKind)) {
            return null;
        }

        return $draw->slots[$slot] ?? null;
    }

    /**
     * The current best with the slots of draw `$index` replaced; the draw keeps its kind, so a
     * repairing replay may clamp it. Null when a slot is negative (no such position).
     *
     * @param list<int> $slots
     * @return list<Draw>|null
     */
    private function with(int $index, array $slots): ?array
    {
        foreach ($slots as $slot) {
            if ($slot < 0) {
                return null;
            }
        }
        $candidate = $this->best;
        $candidate[$index] = new Draw($candidate[$index]->kind, $slots);

        return $candidate;
    }

    /**
     * Evaluates one candidate recording; true when it became the new best.
     *
     * @param list<Draw>|null $candidate
     * @throws BudgetSpent
     */
    private function try(?array $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }
        $key = self::key($candidate);
        if (isset($this->seen[$key])) {
            return false;
        }
        $this->seen[$key] = true;
        [$outcome, $consumed] = $this->evaluate($candidate);
        if (!$outcome->reproduces($this->failure) || Simplicity::compareBuffers($consumed, $this->best) >= 0) {
            return false;
        }
        $this->best = $consumed;
        $this->seen[self::key($consumed)] = true;
        $this->accepted++;

        return true;
    }

    /**
     * Runs one attempt on `$candidate` once the budget allows it.
     *
     * @param list<Draw> $candidate
     * @return array{Outcome, list<Draw>} the outcome and the consumed buffer
     * @throws BudgetSpent
     */
    private function evaluate(array $candidate): array
    {
        if ($this->evaluations >= $this->maxEvaluations) {
            throw new BudgetSpent(StopReason::Budget);
        }
        if (($this->clock)() >= $this->deadline) {
            throw new BudgetSpent(StopReason::Time);
        }
        $this->evaluations++;

        $buffer = ChoiceBuffer::repairing($candidate, $this->floats);
        $outcome = null;
        $this->observer?->begin($candidate);
        try {
            $outcome = ($this->replay)($buffer);
        } catch (Rejected) {
            $outcome = Outcome::discarded();
        } catch (InvalidChoice) {
            $outcome = Outcome::invalid();
        } finally {
            $this->observer?->end($candidate, $outcome);
        }

        return [$outcome, $buffer->consumed()];
    }

    /**
     * @param list<Draw> $draws
     */
    private static function key(array $draws): string
    {
        return json_encode(array_map(static fn(Draw $draw): array => [$draw->kind->name, $draw->slots], $draws), JSON_THROW_ON_ERROR);
    }
}
