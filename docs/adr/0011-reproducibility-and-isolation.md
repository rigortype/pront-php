# ADR-0011: Reproducibility and Execution Isolation

- Status: Accepted
- Date: 2026-11-07
- Amended: 2026-11-08 (Stage 1 findings: the definition of "same failure", when a counterexample is
  flaky, the replay token format)
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
  (provisional; tuning owned by an ADR). "Consistently" means every re-run
  gives the same failure (below); all N re-runs always run and the report
  gives the count. A target is also flaky when the shrinker's first
  evaluation, a replay of the failing buffer, does not reproduce the failure
  (nothing is then shrunk), even if all N re-runs reproduce it. Stage 1 as
  merged reports that case as a plain failure; the rule follows in #36.
  Reporting it as a plain failure when all re-runs reproduce was rejected: the
  failing buffer has then already not failed consistently.
- **Same failure.** The shrinker accepts a candidate only if it "still fails"
  (ADR-0002), and a re-run reproduces only the same failure. Two failures are
  the same when their *failure signatures* are equal strings. A thrown
  failure's signature is the exception class, the file and line where the
  throwable was created (PHP records its file, line and trace at `new`, not at
  `throw`), and the file, line and function of every frame between that site
  and the code that classifies it, minus the harness frames (those shared with
  the classifier's own stack, and those above the harness's call into user
  code); it never contains the message or the arguments, which usually embed
  the input. So one helper reached from two call sites gives two failures, and
  a failure whose stack depth depends on the input only shrinks among inputs
  of the same depth. A failure an oracle decides (a return type, an
  `#[Ensures]` condition) has a signature naming the check that failed and,
  for a return type, the returned value's debug type, again without the input.
- The PRNG is `Random\Engine\Xoshiro256StarStar`; global `mt_srand` is not
  used. `random_int` is not used. Pront maps engine output to ranges itself;
  `Random\Randomizer` is not used, because its mapping is PHP-internal and
  would tie replay to the PHP version.
- The replay token includes the Pront version and an algorithm identifier that
  covers the range mapping (Stage 1: `pront-1`, whose coverage is listed in
  ADR-0004 item 4). Its text form is `pront:` followed by the unpadded
  base64url encoding of the JSON object `{"v": version, "a": algorithm, "t":
  "Class::method", "s": target seed, "k": k, "b": buffer}`; `b` is `null` for a
  fresh trial (regenerated from `s` and `k`) and otherwise the recorded draws
  as `[draw kind, [slots]]` pairs (a shrink candidate, a flaky re-run or the
  counterexample), replayed with repair (ADR-0002 section 4). The token holds
  no shell metacharacters. A token from another version or algorithm, a
  damaged one, or one naming no verified target is refused (exit 2,
  ADR-0013). Replay is guaranteed within one Pront version on
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
