# ADR-0011: Reproducibility and Execution Isolation

- Status: Proposed
- Date: 2026-11-07
- Refines: ADR-0008 (Pillars 2, 7; scope principles)

## 1. Reproducibility
- **All randomness is drawn through the choice buffer.** For each draw, Pront
  defines an ordering of the permitted values in which the simplest value comes
  first: the range's origin (0 if the range contains 0, otherwise the end
  nearest 0), then alternating outward. The buffer records the *position* in
  that ordering (one or more integer slots) (not engine bytes, and not the engine's own range mapping), so
  position 0 is always the simplest value of whatever range the draw has. The ADR
  defines, per draw kind (integers, floats, booleans, enums, literals, unions,
  nullables, characters, lengths), which value is simplest (for integers it
  refines the origin rule above), how the ordering is
  represented (it must cover full-width integer ranges and floats including
  NaN and infinities) and the tie order for signed values. Fresh draws come
  from the PRNG below; a replay feeds recorded positions back; shrinking lowers
  positions (algorithm: ADR). When an earlier draw changes so that a later
  recorded position no longer fits its range, the shrinker may *repair* the
  candidate (for example by clamping), but a repaired candidate is always
  re-run and kept only if it still fails; a position is never altered without
  re-running. Joint edits of dependent draws are an ADR matter.
- The contract covers Pront's own randomness only. If the code under test is
  itself non-deterministic (time, its own randomness, I/O), reproduction is not
  guaranteed. The minimal counterexample is re-run N times and reported as
  "not reproducible (flaky)" if it does not fail consistently. Initial N = 5
  (provisional; tuning owned by an ADR).
- The PRNG is `Random\Engine\Xoshiro256StarStar`; global `mt_srand` is not
  used. `random_int` is not used. Pront maps engine output to ranges itself;
  `Random\Randomizer` is not used, because its mapping is PHP-internal and
  would tie replay to the PHP version.
- The replay token includes the Pront version and an algorithm identifier that
  covers the range mapping. Replay is guaranteed within one Pront version on
  64-bit PHP 8.5+ for numeric draws; string content may depend on the PHP and
  ICU build (a known limitation); 32-bit builds are unsupported. Before 1.0,
  replay is not guaranteed across Pront versions.
- Failures report the seed and the minimal counterexample's choice buffer.

## 2. Execution and isolation
- Pront executes real code. Selecting side-effect-free targets is the user's
  responsibility, and `#[Verify]` is the explicit opt-in. Sandboxing
  (database, filesystem, network isolation) is a non-goal.
- Requirement: fatal errors (memory or time limits) must not lose
  reproducibility; the seed and buffer are journaled before each trial. The
  mechanism (child process per method or per trial, where the shrinker runs,
  watchdog for time limits, portability) is left to an ADR.
- `pront.php` is re-evaluated wherever trials run; nothing relies on
  serializing closures.
- `$self` for instance methods is built by the class-type rule in ADR-0010.
