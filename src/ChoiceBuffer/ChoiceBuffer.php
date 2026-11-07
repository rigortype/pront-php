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

    /** @var list<int>|null fresh slots of the draw in progress */
    private ?array $fresh = null;

    /**
     * @param list<list<int<0, max>>>|null $recorded
     */
    private function __construct(
        private readonly ?Prng $prng,
        private readonly ?array $recorded,
        private readonly FloatCodec $floats,
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

        return new self(null, $draws, $floats);
    }

    /**
     * An `int` in [`$min`, `$max`]; slots `[magnitude, side]` (`IntOrder`).
     */
    public function drawInt(int $min, int $max): int
    {
        IntOrder::origin($min, $max);
        $this->begin(fn(Prng $prng): array => IntOrder::encode($prng->intInRange($min, $max), $min, $max));
        $value = IntOrder::decode($this->slot(), $this->slot(), $min, $max);
        $this->end(DrawKind::Int);

        return $value;
    }

    /**
     * A `float`, including `NAN`, `INF` and `-0.0` through the edge table (`FloatCodec`).
     */
    public function drawFloat(): float
    {
        $this->begin($this->floats->freshSlots(...));
        $value = match ($kind = $this->slot()) {
            0 => $this->floats->kindZero($this->slot(), $this->slot()),
            1 => $this->floats->kindOne($this->slot(), $this->slot(), $this->slot()),
            2 => $this->floats->kindTwo($this->slot()),
            default => throw new InvalidChoice("float kind slot {$kind} is not 0, 1 or 2"),
        };
        $this->end(DrawKind::Float);

        return $value;
    }

    /**
     * A `bool`; `false` is the simplest.
     */
    public function drawBool(): bool
    {
        return $this->drawBelow(2, DrawKind::Bool) === 1;
    }

    /**
     * Whether a nullable value is `null`; `null` (true) is the simplest.
     */
    public function drawIsNull(): bool
    {
        return $this->drawBelow(2, DrawKind::Nullable) === 0;
    }

    /**
     * An index in [0, `$count`) for an enum case, a literal or a union branch, in declaration order.
     */
    public function drawIndex(int $count): int
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('An index draw needs at least one choice');
        }

        return $this->drawBelow($count, DrawKind::Index);
    }

    /**
     * A Unicode scalar value (code point) in the character ordering (`Characters`).
     */
    public function drawCharacter(): int
    {
        $this->begin(fn(Prng $prng): array => [Characters::positionOf(Characters::fresh($prng))]);
        $codePoint = Characters::codePointAt($this->slot());
        $this->end(DrawKind::Character);

        return $codePoint;
    }

    /**
     * A length in [`$min`, `$max`], `$max` defaulting to `DEFAULT_MAX_LENGTH`; the slot is
     * `length - min`.
     */
    public function drawLength(int $min, ?int $max = null): int
    {
        $max ??= self::DEFAULT_MAX_LENGTH;
        if ($min < 0 || $max < $min) {
            throw new \InvalidArgumentException("Invalid length range [{$min}, {$max}]");
        }

        return $min + $this->drawBelow($max - $min + 1, DrawKind::Length);
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
        $slot = $this->slot();
        if ($slot > $edgeCount) {
            throw new InvalidChoice("selector slot {$slot} exceeds the {$edgeCount} edge candidates");
        }
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
     * A single-slot draw over [0, `$count`) whose position is the value.
     */
    private function drawBelow(int $count, DrawKind $kind): int
    {
        $this->begin(static fn(Prng $prng): array => [$prng->uniformUnsigned($count - 1)]);
        $slot = $this->slot();
        if ($slot >= $count) {
            throw new InvalidChoice("{$kind->name} slot {$slot} is outside [0, {$count})");
        }
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
            $value = $this->recorded[count($this->consumed)][$index] ?? 0;
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
