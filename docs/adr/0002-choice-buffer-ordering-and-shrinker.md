# ADR-0002: Choice-buffer ordering and shrinker

- Status: Accepted
- Date: 2026-11-07
- Refines: ADR-0008 (Pillars 2, 7), ADR-0011 section 1, ADR-0010 section 4

## Context

ADR-0011 section 1 fixes the rule: all randomness goes through the choice buffer, the buffer records a
position in a per-draw ordering whose first element is the simplest value, and a shrink candidate
that no longer fits may be repaired but is always re-run. It leaves to this ADR: which value is
simplest per draw kind, how the ordering is represented, the tie order for signed values, and the
shrinker's passes and budget.

Facts verified on PHP 8.5.12: `-(PHP_INT_MIN + 1)` equals `PHP_INT_MAX`, so the magnitude of any
negative `int` minus one fits an `int`; a buffer of plain `int` indexes therefore needs no big
numbers if the sign is recorded separately.

## Decision

### 1. Representation
A draw is recorded as a short list of non-negative `int` slots, never as engine bytes. A draw's
*position in its ordering* (section 2) defines which value is simpler; "simpler" is never derived from
a raw slot comparison unless this ADR says so. The all-zero slot list is always the simplest value of
the draw's range.

### 2. Simplest value and ordering, per draw kind
- **int in [min, max]**: the *origin* is 0 if the range contains 0, otherwise the end nearest 0. The
  normative order is by distance `|d|` from the origin ascending, positive before negative at equal
  distance: origin, origin+1, origin-1, origin+2, ... Values outside the range are skipped.
  Slots: `magnitude` and `side`. Side 0 stores the distance `d` itself; side 1 stores `d - 1` (which
  keeps it within `int`, because `-(PHP_INT_MIN + 1)` equals `PHP_INT_MAX`). Comparing two draws A and
  B must not overflow: with equal sides compare the magnitudes; for A on side 0 (distance `mA`) and B on
  side 1 (distance `mB + 1`), A is simpler iff `mA - mB <= 1` (positive wins the tie); for A on side 1
  and B on side 0, A is simpler iff `mB > mA && mB - mA > 1`. Test vector, range [-4, 4]: 0, 1, -1, 2,
  -2, 3, -3, 4, -4 in this order, with (m, side) = (0,0), (1,0), (0,1), (2,0), (1,1), (3,0), (2,1),
  (4,0), (3,1).
- **float**: a `kind` slot and a payload; the `kind` slot is the float's own edge-or-uniform selector
  (a float has no separate selector draw, and the generic "slot 0 means uniform" rule below does not
  apply to it): `kind` 0 is a small integer value (0.0, 1.0, -1.0, 2.0, ...
  drawn as an `int` with the rule above, within +/-16 when drawn fresh); `kind` 1 is a general finite
  value as three slots (high 31 magnitude bits, low 32 magnitude bits, sign); non-finite patterns,
  `-0.0`, and any integer-valued pattern inside the `kind` 0 range are rejected, so no value has
  two encodings; ordered by magnitude bits ascending then positive first, so slot lowering applies to the
  first two slots. `kind` 2 indexes the float edge table of ADR-0007 section 1 (the only table; this
  ADR keeps no copy). `NAN` and `INF` occur only through `kind` 2, and each value has exactly one
  encoding: `0.0` is `[0, 0, 0]`, `1.0` is `[0, 1, 0]` (kind, magnitude, side), `NAN` is `[2, 1]`. Simplest is 0.0. A shrink candidate may replace a `kind` 1 or 2 value by an
  integer-valued `kind` 0 neighbour (for example the truncated value).
- **bool**: `false` is 0. **enum, literal, union branch**: declaration order; the first is 0.
  **nullable**: `null` is 0.
- **character** (for strings): the order is `a`-`z`, `A`-`Z`, `0`-`9`, space, ASCII punctuation, then
  the remaining Unicode scalar values by code point. Simplest is `a`.
- **length** (string, list, array): the range's minimum length is 0 in the ordering, then ascending.
  For `non-empty-*` the minimum is 1. A string length counts characters at draw time (ADR-0007).
- **edge-or-uniform selector**: the first draw of each value (ADR-0010 section 4). Slot 0 means uniform;
  slot `k > 0` selects edge candidate `k - 1` of the in-range edge list.

### 3. Fresh draws (mapping from the PRNG)
Fresh values come from the PRNG (`Random\Engine\Xoshiro256StarStar`) through Pront's own mapping,
never `Randomizer`. The requirement is that every permitted value of a bounded draw is equally likely,
without modulo bias (rejection sampling on the 64-bit output; a full-width range uses the raw output
as an unsigned value). The following are provisional weights, reviewed with ADR-0007's defaults:
- **int**: uniform over the permitted values, then encoded as (magnitude, side).
- **float**: `kind` 0 with probability 20%, `kind` 1 with 70% (a uniformly random 64-bit pattern,
  redrawn while it is NaN or infinite), `kind` 2 with 10% (the edge ratio).
- **character**: disjoint classes with weights, uniform within a class: printable ASCII
  (U+0020-U+007E) 70%; the rest of the BMP (U+00A0-U+FFFF without surrogates, the control and the
  combining class) 15%; supplementary planes (U+10000-U+10FFFF without noncharacters) 10%;
  control (U+0000-U+001F, U+007F-U+009F) and combining 5%. The combining class takes precedence over the others (its characters are excluded from them),
  noncharacters (U+FDD0-U+FDEF, U+FFFE, U+FFFF) are excluded from every class, and it is the fixed table
  U+0300-U+036F, U+3099-U+309A, U+200D, U+FE00-U+FE0F, U+E0100-U+E01EF; it does not use `IntlChar`,
  so the mapping is the same with or without `ext-intl`.
- **length**: uniform over the permitted range; when the type states no maximum, the maximum is 16
  elements or characters.

### 4. Shrinker
Passes repeat until a fixed point or the budget is spent. Every candidate is evaluated by re-running
the generator on the edited buffer and re-running the property. A candidate is *accepted* only if it
still fails and is simpler than the current buffer under **shortlex**: a shorter buffer (fewer draws)
is simpler; at equal length, compare draw by draw by position in the ordering.
1. **Block deletion**: remove runs of 8, 4, 2 and 1 consecutive draws.
2. **Slot lowering**: for each slot whose numeric order equals the simplicity order (an int
   `magnitude`, a length, an enum, literal or union index), binary-search toward 0 for the smallest
   value that still fails. Other slots (`side`, float `kind`, the selector) are only tried at their
   neighbouring simpler values, never binary-searched.
3. **Repaired joint edit**: lowering a length draw changes the ranges of dependent draws; a later slot
   that no longer fits is clamped to its new range and the candidate is re-run. A repaired candidate
   is kept only if it still fails (ADR-0011 section 1).

When the generator reads past the end of the buffer, the missing slots read as 0 (the simplest value),
not as a failure of the candidate. Shortlex compares, and the shrinker stores, the *consumed* buffer:
the slots the generator actually read, with zero-filled slots written out. A candidate whose consumed
buffer is not simpler is rejected, so every accepted step strictly decreases and shrinking terminates
without relying on the budget. Edge values take one draw and are therefore minimal by design: a
well-known small value is simpler than a random one (ADR-0010 section 4). The selector is tried at every
lower value, not only its neighbour. A candidate with an out-of-range slot that cannot be
repaired is discarded.

### 5. Budget
The numeric budget (`maxShrinkSteps`, an internal constant, and the wall-time safety net) is owned by
ADR-0007 section 2. The wall time is checked between candidate evaluations; it cannot interrupt user
code that is already running in-process (ADR-0004). Shrinking starts as soon as a failure is found.

## Alternatives rejected

| Candidate | Reason |
| --- | --- |
| Record engine bytes or `Randomizer` output | Ties replay to PHP's internal mapping and breaks "0 is simplest" (ADR-0011). |
| One interleaved index per integer | Overflows `int` for full-width ranges (the sketch's `drawInt` problem, ADR-0015). |
| Read past the end of the buffer = discard the candidate | Blocks shrinking from edge-value draws (shorter buffer) to uniform ones. |
| Discard every out-of-range candidate | Stalls shrinking where draws depend on each other (length vs index into it). |
| Per-type custom shrinkers | Non-goal (ADR-0008). |

## Consequences

- Generators must draw through the buffer API only; no generator may call a random source directly.
- A shrunk counterexample is always a real run of the generator, so invariants hold by construction.
- The float and character orderings are conventions; changing one changes replay-token meaning and is
  an algorithm-identifier bump (ADR-0011).
- Revisit when real-code measurements show the budget or pass order is poor.
