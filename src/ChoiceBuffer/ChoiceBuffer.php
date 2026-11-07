<?php

declare(strict_types=1);

namespace Pront\ChoiceBuffer;

/**
 * The choice buffer behind one trial (ADR-0011 section 1, ADR-0002). Generators draw only through
 * it. Each draw is recorded as a list of non-negative `int` slots holding the value's position in
 * the draw's ordering; the all-zero slot list is the simplest value.
 *
 * - A *fresh* buffer draws from a `Prng` (ADR-0002 section 3).
 * - A *replay* buffer reads a recorded list of draws. A slot past the end of a recorded draw, and
 *   every slot of a draw past the end of the recording, reads as 0 (ADR-0002 section 4). A slot
 *   that is not a valid position throws `InvalidChoice`; nothing is clamped here.
 * - A *repairing* buffer reads a recording of draws with their kinds like a replay, but when the
 *   draw read at a position is of the kind recorded there, a slot that is not a valid position is
 *   clamped to the nearest valid one: the draw's range changed, as after the shrinker lowered a
 *   length (the repaired joint edit, ADR-0002 section 4). The consumed buffer holds the repaired
 *   slots, and `repaired()` says whether anything was clamped. A slot of a draw read at a position
 *   recorded with another kind (an edit shifted it there) is not repaired and throws like a
 *   replay; so does a float kind 1 payload whose clamped slots still form no kind 1 pattern. On a
 *   recording with only valid positions it behaves like a replay.
 *
 * Either way `consumed()` is the buffer the generator actually read, which is what shortlex compares
 * and what a replay feeds back.
 */
final class ChoiceBuffer
{
    /** Maximum length when the type states none (ADR-0002 section 3). */
    public const int DEFAULT_MAX_LENGTH = 16;

    /** @var list<Draw> */
    private array $consumed = [];

    /** @var list<int<0, max>> slots read by the draw in progress */
    private array $current = [];

    /** Number of slots read past the end of the recording so far. */
    private int $zeroFilled = 0;

    /** @var list<int>|null fresh slots of the draw in progress */
    private ?array $fresh = null;

    /** Whether a repairing buffer has clamped a slot. */
    private bool $repaired = false;

    /**
     * @param list<list<int<0, max>>>|null $recorded
     * @param list<DrawKind>|null $kinds the recorded kinds of a repairing buffer
     */
    private function __construct(
        private readonly ?Prng $prng,
        private readonly ?array $recorded,
        private readonly FloatCodec $floats,
        private readonly ?array $kinds = null,
    ) {
    }

    public static function fresh(Prng $prng, FloatCodec $floats = new FloatCodec()): self
    {
        return new self($prng, null, $floats);
    }

    /**
     * @param list<list<int>> $draws the recorded slots per draw, as `consumedSlots()` returns them
     */
    public static function replay(array $draws, FloatCodec $floats = new FloatCodec()): self
    {
        return new self(null, self::validRecording($draws), $floats);
    }

    /**
     * A replay that clamps invalid positions of draws of the recorded kind instead of throwing (see
     * the class comment).
     *
     * @param list<Draw> $draws the recorded draws, as `consumed()` returns them or edited
     */
    public static function repairing(array $draws, FloatCodec $floats = new FloatCodec()): self
    {
        if (!array_is_list($draws)) {
            throw new \InvalidArgumentException('A recorded buffer must be a list of draws');
        }
        $slots = [];
        $kinds = [];
        foreach ($draws as $draw) {
            if (!$draw instanceof Draw) {
                throw new \InvalidArgumentException('A repairing buffer records Draw objects');
            }
            $slots[] = $draw->slots;
            $kinds[] = $draw->kind;
        }

        return new self(null, self::validRecording($slots), $floats, $kinds);
    }

    /**
     * Whether this repairing buffer clamped at least one slot so far.
     */
    public function repaired(): bool
    {
        return $this->repaired;
    }

    /**
     * @param list<list<int>> $draws
     * @return list<list<int<0, max>>>
     */
    private static function validRecording(array $draws): array
    {
        if (!array_is_list($draws)) {
            throw new \InvalidArgumentException('A recorded buffer must be a list of draws');
        }
        foreach ($draws as $slots) {
            if (!is_array($slots) || !array_is_list($slots)) {
                throw new \InvalidArgumentException('A recorded draw must be a list of slots');
            }
            foreach ($slots as $slot) {
                if (!is_int($slot) || $slot < 0) {
                    throw new \InvalidArgumentException('A recorded slot must be a non-negative int');
                }
            }
        }
        /** @var list<list<int<0, max>>> $draws */

        return $draws;
    }

    /**
     * An `int` in [`$min`, `$max`]; slots `[magnitude, side]` (`IntOrder`).
     */
    public function drawInt(int $min, int $max): int
    {
        IntOrder::origin($min, $max);
        $this->begin(fn(Prng $prng): array => IntOrder::encode($prng->intInRange($min, $max), $min, $max));
        $value = $this->decodeInt($this->slot(), $this->slot(), $min, $max, DrawKind::Int);
        $this->end(DrawKind::Int);

        return $value;
    }

    /**
     * A `float`, including `NAN`, `INF` and `-0.0` through the edge table (`FloatCodec`). Fresh, the
     * edge table (kind 2) is chosen with probability `$edgeRatio`.
     *
     * A kind 1 payload that is not a valid pattern because some of its slots were read past the end
     * of the recording (zero-filled) decodes as the all-zero draw, `0.0` recorded as `[0, 0, 0]`:
     * reading past the end is never a failure of the candidate (ADR-0002 section 4). A fully
     * recorded invalid payload still throws `InvalidChoice`.
     */
    public function drawFloat(float $edgeRatio = FloatCodec::DEFAULT_EDGE_RATIO): float
    {
        $this->begin(fn(Prng $prng): array => $this->floats->freshSlots($prng, $edgeRatio));
        $value = match ($kind = $this->slot()) {
            0 => (float) $this->decodeInt($this->slot(), $this->slot(), -FloatCodec::KIND0_LIMIT, FloatCodec::KIND0_LIMIT, DrawKind::Float, 1),
            1 => $this->kindOne(),
            2 => $this->floats->kindTwo($this->clampSlot(1, $this->slot(), count($this->floats->edges) - 1, 'float edge index', DrawKind::Float)),
            default => $this->repairs(DrawKind::Float)
                ? $this->repairedFloatKind()
                : throw new InvalidChoice("float kind slot {$kind} is not 0, 1 or 2"),
        };
        $this->end(DrawKind::Float);

        return $value;
    }

    /**
     * A `bool`; `false` is the simplest.
     */
    public function drawBool(): bool
    {
        return $this->drawUpTo(1, DrawKind::Bool) === 1;
    }

    /**
     * Whether a nullable value is `null`; `null` (true) is the simplest.
     */
    public function drawIsNull(): bool
    {
        return $this->drawUpTo(1, DrawKind::Nullable) === 0;
    }

    /**
     * An index in [0, `$count`) for an enum case, a literal or a union branch, in declaration order.
     */
    public function drawIndex(int $count): int
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('An index draw needs at least one choice');
        }

        return $this->drawUpTo($count - 1, DrawKind::Index);
    }

    /**
     * A Unicode scalar value (code point) in the character ordering (`Characters`).
     */
    public function drawCharacter(): int
    {
        $this->begin(fn(Prng $prng): array => [Characters::positionOf(Characters::fresh($prng))]);
        $codePoint = Characters::codePointAt($this->clampSlot(0, $this->slot(), Characters::POSITION_COUNT - 1, 'character position', DrawKind::Character));
        $this->end(DrawKind::Character);

        return $codePoint;
    }

    /**
     * A length in [`$min`, `$max`]; the slot is `length - min`. With no `$max` the maximum is
     * `DEFAULT_MAX_LENGTH`, or `$min` when that is larger, so a type whose minimum length exceeds
     * the default stays generatable.
     */
    public function drawLength(int $min, ?int $max = null): int
    {
        $max ??= max(self::DEFAULT_MAX_LENGTH, $min);
        if ($min < 0 || $max < $min) {
            throw new \InvalidArgumentException("Invalid length range [{$min}, {$max}]");
        }

        return $min + $this->drawUpTo($max - $min, DrawKind::Length);
    }

    /**
     * The edge-or-uniform selector, the first draw of each value (ADR-0010 section 4): `null` means
     * uniform (slot 0); otherwise the index of the in-range edge candidate (slot `index + 1`).
     * Fresh, an edge is chosen with probability `$edgeRatio`, uniformly among the `$edgeCount`
     * candidates; with no candidates the draw is uniform.
     */
    public function drawSelector(int $edgeCount, float $edgeRatio): ?int
    {
        if ($edgeCount < 0) {
            throw new \InvalidArgumentException('The edge count must not be negative');
        }
        if (!($edgeRatio >= 0.0 && $edgeRatio <= 1.0)) {
            throw new \InvalidArgumentException('The edge ratio must be within [0, 1]');
        }
        $this->begin(static fn(Prng $prng): array => [
            $edgeCount > 0 && $prng->chance($edgeRatio) ? 1 + $prng->uniformUnsigned($edgeCount - 1) : 0,
        ]);
        $slot = $this->clampSlot(0, $this->slot(), $edgeCount, "selector slot over {$edgeCount} edge candidates", DrawKind::Selector);
        $this->end(DrawKind::Selector);

        return $slot === 0 ? null : $slot - 1;
    }

    /**
     * The consumed buffer: every draw the generator made, with the slots it read.
     *
     * @return list<Draw>
     */
    public function consumed(): array
    {
        return $this->consumed;
    }

    /**
     * The consumed buffer as plain slot lists, the form `replay()` accepts.
     *
     * @return list<list<int<0, max>>>
     */
    public function consumedSlots(): array
    {
        return array_map(static fn(Draw $draw): array => $draw->slots, $this->consumed);
    }

    /**
     * The kind 1 payload of the float draw in progress (see `drawFloat()`).
     */
    private function kindOne(): float
    {
        $zeroFilledBefore = $this->zeroFilled;
        $high = $this->clampSlot(1, $this->slot(), 0x7FFFFFFF, 'float kind 1 high', DrawKind::Float);
        $low = $this->clampSlot(2, $this->slot(), 0xFFFFFFFF, 'float kind 1 low', DrawKind::Float);
        $sign = $this->clampSlot(3, $this->slot(), 1, 'float kind 1 sign', DrawKind::Float);
        if ($this->zeroFilled > $zeroFilledBefore
            && $high <= 0x7FFFFFFF && $low <= 0xFFFFFFFF && $sign <= 1
            && !$this->floats->isKindOnePattern(($high << 32) | $low | ($sign << 63))
        ) {
            $this->current = [0, 0, 0];

            return $this->floats->kindZero(0, 0);
        }

        return $this->floats->kindOne($high, $low, $sign);
    }

    /**
     * Whether a slot of the draw in progress, of `$kind`, may be clamped: this is a repairing buffer
     * and the recording holds a draw of the same kind at this position.
     */
    private function repairs(DrawKind $kind): bool
    {
        return $this->kinds !== null && ($this->kinds[count($this->consumed)] ?? null) === $kind;
    }

    /**
     * The repair of a float `kind` slot above 2: kind 2 with its index clamped to the table.
     */
    private function repairedFloatKind(): float
    {
        $this->current[0] = 2;
        $this->repaired = true;

        return $this->floats->kindTwo($this->clampSlot(1, $this->slot(), count($this->floats->edges) - 1, 'float edge index', DrawKind::Float));
    }

    /**
     * Decodes the int position whose slots are at `$offset` and `$offset + 1` of the draw in
     * progress; a repairing buffer clamps it first (`IntOrder::clamp()`) when the draw is of the
     * recorded kind.
     */
    private function decodeInt(int $magnitude, int $side, int $min, int $max, DrawKind $kind, int $offset = 0): int
    {
        if ($this->repairs($kind)) {
            [$clampedMagnitude, $clampedSide] = IntOrder::clamp($magnitude, $side, $min, $max);
            if ($clampedMagnitude !== $magnitude || $clampedSide !== $side) {
                $this->current[$offset] = $magnitude = $clampedMagnitude;
                $this->current[$offset + 1] = $side = $clampedSide;
                $this->repaired = true;
            }
        }

        return IntOrder::decode($magnitude, $side, $min, $max);
    }

    /**
     * Slot `$index` of the draw in progress, which must be at most `$max`: a replay throws when it is
     * not, a repairing buffer clamps it to `$max` when the draw is of the recorded kind.
     *
     * @param int<0, max> $value
     * @param int<0, max> $max
     * @return int<0, max>
     */
    private function clampSlot(int $index, int $value, int $max, string $what, DrawKind $kind): int
    {
        if ($value <= $max) {
            return $value;
        }
        if (!$this->repairs($kind)) {
            throw new InvalidChoice("{$what} {$value} is outside [0, {$max}]");
        }
        $this->current[$index] = $max;
        $this->repaired = true;

        return $max;
    }

    /**
     * A single-slot draw over [0, `$maxPosition`] whose position is the value. An inclusive maximum,
     * so a length range up to `PHP_INT_MAX` does not overflow.
     *
     * @param int<0, max> $maxPosition
     */
    private function drawUpTo(int $maxPosition, DrawKind $kind): int
    {
        $this->begin(static fn(Prng $prng): array => [$prng->uniformUnsigned($maxPosition)]);
        $slot = $this->clampSlot(0, $this->slot(), $maxPosition, "{$kind->name} slot", $kind);
        $this->end($kind);

        return $slot;
    }

    /**
     * Starts a draw; in fresh mode `$fresh` produces its slots.
     *
     * @param callable(Prng): list<int> $fresh
     */
    private function begin(callable $fresh): void
    {
        $this->current = [];
        $this->fresh = $this->prng !== null ? $fresh($this->prng) : null;
    }

    /**
     * @return int<0, max>
     */
    private function slot(): int
    {
        $index = count($this->current);
        if ($this->fresh !== null) {
            $value = $this->fresh[$index] ?? throw new \LogicException('A fresh draw read more slots than it made');
            if ($value < 0) {
                throw new \LogicException('A fresh draw made a negative slot');
            }
        } else {
            $value = $this->recorded[count($this->consumed)][$index] ?? null;
            if ($value === null) {
                $value = 0;
                $this->zeroFilled++;
            }
        }
        $this->current[] = $value;

        return $value;
    }

    private function end(DrawKind $kind): void
    {
        $this->consumed[] = new Draw($kind, $this->current);
        $this->current = [];
        $this->fresh = null;
    }
}
