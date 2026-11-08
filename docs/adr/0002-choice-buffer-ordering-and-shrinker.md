# ADR-0002: Choice-buffer ordering and shrinker

- Status: Accepted
- Date: 2026-11-07
- Amended: 2026-11-08 (Stage 1 findings: float `kind` 1 rejection list, zero-filled `kind` 1
  payload, noncharacters, which values draw a selector, default maximum length, repair scope, the edge
  ratio's float weight; the always-recorded uniform draw of `pront-2`)
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
  `-0.0`, any integer-valued pattern inside the `kind` 0 range ([-2^53, 2^53], where every integer is an
  exact float), and the bit pattern of every edge-table entry (`PHP_FLOAT_EPSILON`, `PHP_FLOAT_MIN`,
  `PHP_FLOAT_MAX` are finite, so they would otherwise be both `kind` 1 and `kind` 2) are rejected, so no
  value has two encodings; ordered by magnitude bits ascending then positive first, so slot lowering applies to the
  first two slots. `kind` 2 indexes the float edge table of ADR-0007 section 1 (the only table; this
  ADR keeps no copy). `NAN` and `INF` occur only through `kind` 2, and each value has exactly one
  encoding: `0.0` is `[0, 0, 0]`, `1.0` is `[0, 1, 0]` (kind, magnitude, side), `NAN` is `[2, 1]`. Simplest is 0.0. A shrink candidate may replace a `kind` 1 or 2 value by an
  integer-valued `kind` 0 neighbour (for example the truncated value).
- **bool**: `false` is 0. **enum, literal, union branch**: declaration order; the first is 0.
  **nullable**: `null` is 0. A bool draws a selector first (both values are edges, ADR-0007); an enum,
  a literal, a union branch and an array shape draw none (they have no edge list; a union member draws
  its own type's selector).
- **character** (for strings): the order is `a`-`z`, `A`-`Z`, `0`-`9`, space, ASCII punctuation, then
  the remaining Unicode scalar values by code point. Simplest is `a`. The draw yields a code point; the
  string generator encodes it as UTF-8.
- **length** (string, list, array): the range's minimum length is 0 in the ordering, then ascending.
  For `non-empty-*` the minimum is 1. A string length counts characters at draw time (ADR-0007).
- **edge-or-uniform selector**: the first draw of each value (ADR-0010 section 4). Slot 0 means uniform;
  slot `k > 0` selects edge candidate `k - 1` of the in-range edge list.
  From algorithm identifier `pront-2` on, every value that has edge values always records its uniform
  draws after the selector, also when the selector picks an edge (they are then not used to build the
  value): an int its (magnitude, side) draw, a bool its bool draw, a string its length draw and that
  many character draws, a list or array its uniform length draw (the elements follow the length
  actually used). An edge value has a *uniform encoding* (the uniform draws that produce the same
  value) always for an int, a bool and a list or array length (their in-range edges are in the
  uniform range), and for a string unless it is invalid UTF-8 or longer than the uniform maximum
  length in characters (code points; for example the 1000-character edge, ADR-0007, whose table entry
  `EdgeValues::LONG_STRING_BYTES` is counted in bytes, which for `a` is the same number). The
  shrinker's *edge re-encoding* pass (section 4) turns an edge into that encoding, after which slot
  lowering and block deletion move it on to a small uniform value: a failing `PHP_INT_MAX` can
  shrink to `10`, and a list at the edge length 16 to a shorter uniform length. When a fresh draw
  picks an edge, the generator records the edge's own uniform encoding as the unused draws (a fresh
  uniform value when it has none); for an int, a bool or a list length this is only an
  optimisation, and for a string it makes the re-encoded candidate the same length as the recorded
  one. An edge without a uniform encoding shrinks only to another edge or to a different uniform
  value that still fails. A float is unchanged: its `kind` slot is its selector, and its edges stay
  minimal unless an integer-valued `kind` 0 neighbour still fails (`PHP_FLOAT_MAX`, `INF` and
  `-INF` have no equal-valued `kind` 0 neighbour).
  Under `pront-1` an edge is one draw and a uniform value two or more, so shortlex keeps the edge
  (PR #28 findings 1 and 2). This is the accepted design; `pront-1` (Stage 1 as merged) draws the
  uniform value only after selector 0, and the implementation follows in #34.

### 3. Fresh draws (mapping from the PRNG)
Fresh values come from the PRNG (`Random\Engine\Xoshiro256StarStar`) through Pront's own mapping,
never `Randomizer`. The requirement is that every permitted value of a bounded draw is equally likely,
without modulo bias (rejection sampling on the 64-bit output; a full-width range uses the raw output
as an unsigned value). The following are provisional weights, reviewed with ADR-0007's defaults:
- **int**: uniform over the permitted values, then encoded as (magnitude, side).
- **float**: `kind` 2 with the probability of the edge ratio (10% by default, ADR-0007), which is
  how the ratio reaches floats; `kind` 0 and `kind` 1 share the rest 20:70 (a `kind` 1 draw is a
  uniformly random 64-bit pattern, redrawn while it is rejected by section 2).
- **character**: disjoint classes with weights, uniform within a class: printable ASCII
  (U+0020-U+007E) 70%; the rest of the BMP (U+00A0-U+FFFF without surrogates, the control and the
  combining class) 15%; supplementary planes (U+10000-U+10FFFF without noncharacters) 10%;
  control (U+0000-U+001F, U+007F-U+009F) and combining 5%. The combining class takes precedence over the others (its characters are excluded from them),
  all 66 Unicode noncharacters (U+FDD0-U+FDEF, and U+nFFFE and U+nFFFF in each of the 17 planes) are
  excluded from every class (the character ordering still covers them, so a replayed or shrunk buffer
  may hold one), and the combining class is the fixed table
  U+0300-U+036F, U+3099-U+309A, U+200D, U+FE00-U+FE0F, U+E0100-U+E01EF; it does not use `IntlChar`,
  so the mapping is the same with or without `ext-intl`.
- **length**: uniform over the permitted range; when the type states no maximum, the maximum is
  `max(16, minimum)` elements or characters, so a type whose minimum exceeds 16 is still generatable.

### 4. Shrinker
Passes repeat until a fixed point or the budget is spent. Every candidate is evaluated by re-running
the generator on the edited buffer and re-running the property. A candidate is *accepted* only if it
still fails and is simpler than the current buffer under **shortlex**: a shorter buffer (fewer draws)
is simpler; at equal length, compare draw by draw by position in the ordering.
0. **Edge re-encoding** (from `pront-2` on; first in every round): for each selector `k > 0` whose
   edge has a uniform encoding (section 2), one candidate sets the selector to 0 and rewrites the
   uniform draws that follow it to that encoding, computed by the shrinker from the edge value (the
   int position, the bool, the length slot and the character positions), whatever the unused draws
   hold (block deletion or repair may have zero-filled or shifted them). The value is unchanged, so
   the candidate fails as before; at equal or shorter length it is simpler (lower selector). For a
   string whose recorded unused draws are fewer characters than the edge, the candidate is longer
   and is rejected; the generation-time encoding (section 2) avoids this for a fresh trial, and the
   pass runs before block deletion in the first round.
1. **Block deletion**: remove runs of 8, 4, 2 and 1 consecutive draws.
2. **Slot lowering**: for each slot whose numeric order equals the simplicity order (an int
   `magnitude`, a length, an enum, literal or union index), binary-search toward 0 for the smallest
   value that still fails. Other slots (`side`, float `kind`) are only tried at their neighbouring
   simpler values, never binary-searched; the selector is tried at every lower value. Slots are
   lowered in buffer order, so a selector is lowered before the uniform draws that follow it.
3. **Repaired joint edit**: lowering a length draw changes the ranges of dependent draws; a later slot
   that no longer fits is clamped to its new range and the candidate is re-run. A repaired candidate
   is kept only if it still fails (ADR-0011 section 1). Repair applies only when the draw read at a
   position has the kind recorded there (its range changed); a slot that an edit shifted onto a draw
   of another kind is not repaired, and if it does not fit, the candidate is invalid. Repair clamps a
   slot to the nearest valid position of its draw: an int position, a length, an index, a bool, a
   nullable flag, a selector, a character, the magnitude and side of a `kind` 0 float, a float `kind`
   above 2 (to `kind` 2 with its index clamped), a float edge index, and the low magnitude and sign slots of a `kind` 1 float. A `kind` 1
   high slot is never clamped (every pattern with it at its limit is non-finite), and a `kind` 1
   pattern that section 2 rejects is never repaired. Every attempt that runs a recorded buffer
   repairs (`ChoiceBuffer::repairing`): a shrink candidate, a flaky re-run, a replay token that
   carries a buffer (ADR-0011), and the rendering of a counterexample. A non-repairing read of a
   recorded buffer (`ChoiceBuffer::replay`) throws on an out-of-range slot.

When the generator reads past the end of the buffer, the missing slots read as 0 (the simplest value),
not as a failure of the candidate. A float whose `kind` 1 payload is invalid only because at least one
of its slots was read past the end (the all-zero payload is the pattern of `0.0`, which `kind` 1
rejects) decodes as the all-zero draw `0.0` and is recorded as `[0, 0, 0]`; a fully recorded invalid
payload still makes the candidate invalid, and so does an out-of-range slot that repair does not
clamp. Shortlex compares, and the shrinker stores, the *consumed* buffer:
the slots the generator actually read, with zero-filled slots written out. A candidate whose consumed
buffer is not simpler is rejected, so every accepted step strictly decreases and shrinking terminates
without relying on the budget. Under `pront-1` edge values take one draw and are therefore minimal by
design (a well-known small value is simpler than a random one); from `pront-2` on an edge and a
uniform value of the same value take the same number of draws and the uniform one (selector 0) is
simpler, so the edge re-encoding pass turns an edge that has a uniform encoding into a uniform value
that shrinks further (section 2, ADR-0010 section 4). The selector is tried at every
lower value, not only its neighbour. A candidate with an out-of-range slot that cannot be
repaired is invalid (not a discard).

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
| Record the uniform draws only after selector 0 (`pront-1`) | An edge is one draw and a uniform value more, so shortlex can never move an edge to a smaller uniform value (`PHP_INT_MAX` instead of `10`, a list stuck at length 16). |

## Consequences

- Generators must draw through the buffer API only; no generator may call a random source directly.
- A shrunk counterexample is always a real run of the generator, so invariants hold by construction.
- The float and character orderings are conventions; changing one changes replay-token meaning and is
  an algorithm-identifier bump (ADR-0011).
- Revisit when real-code measurements show the budget or pass order is poor.
