# ADR-0013: Tooling

- Status: Accepted
- Date: 2026-11-07
- Amended: 2026-11-08 (Stage 1 findings: candidate files, CLI options, exit codes, `--strict`
  items, replay outcomes)
- Refines: ADR-0008 (scope principles)

## 1. Discovery
Static file analysis (`nikic/php-parser`) over the configured `paths` picks
candidates and builds the parent-to-implementation index. Pront loads only
candidate files directly. A *candidate file* is a file in scope that holds a
Pront Attribute or a `Verify`/`Ensures` look-alike (an Attribute of that short
name outside `Pront\Attribute\`, reported if it resolves to no class), a file
that redeclares a class carrying Pront Attributes, or a file that declares an
in-scope descendant of a contract class, found statically or through the
out-of-index ancestors that ADR-0012 section 1 autoloads. In-scope parents load
before their children. Loading a candidate runs its top-level code and
autoloads its parents, interfaces and Attribute classes; `pront.php` is
evaluated; and the code under test may load further files. Pront promises no
more than this.

## 2. Configuration
`pront.php` returns a config object: paths, excludes, default iteration
counts, custom generator and comparator registration, contract registration.

## 3. Runner and reporting
- Primary runner: the CLI `vendor/bin/pront`. A PHPUnit bridge comes later.
- Stage 1 CLI options: `--config <file>`, `--seed <int>`, `--strict`,
  `--replay <token>`, `--version` and `--help` (also `-h`) (`--opt v` or
  `--opt=v`). An
  unknown option, a positional argument, a repeated option, and `--seed` with
  `--replay` are usage errors (exit 2). There is no option for iterations or
  the edge ratio; both are `#[Verify]` options (ADR-0010 section 4).
- The report ends with a summary line that always shows the counts of skipped
  and unchecked items, reserved-name conflicts (ADR-0009) and "unverified until
  Stage 3" classes (ADR-0012), even when zero.
- Exit codes: 0 success; 1 a counterexample was found (also when other issues
  occurred in the same run); 2 a configuration or unresolved-generator error,
  a runtime "unverifiable" error (a value that cannot be compared or
  snapshotted, ADR-0010, or a `TypeError` raised while binding a value to a
  condition parameter, ADR-0009), a target that exceeded the discard threshold
  (ADR-0010 section 5), a replay error (below), a `paths` setting that matches
  no `#[Verify]` ("nothing to verify"), or, with `--strict`, a skipped
  implementation, an unchecked return-type fragment, a reserved-name conflict
  or a class "unverified until Stage 3" (ADR-0012). Without `--strict`, those
  items are listed and the exit code stays 0. CI should run with `--strict`.
  An exception from a method without `@throws` (ADR-0009 section 5) is listed
  and counted as "unchecked" but is not a `--strict` item, because failing on
  it would ask for `@throws` written for Pront's sake. Attempts that earlier
  runs left unfinished (ADR-0004 item 8) are printed and counted, and do not
  change the exit code.
- Other outcomes: a counterexample that does not reproduce (flaky) is reported
  as such and still counts as a counterexample (exit 1); a fatal or time-limit
  error inside a trial counts as a failure (exit 1, with the journaled seed and
  buffer); a `--replay` token from an incompatible Pront version, and any
  pre-run error, give exit 2; a `--replay` that no longer fails reports "no
  longer reproduces" (exit 0), and one whose target no longer exists gives
  exit 2. A replay whose recorded draw is read as another draw kind (the
  target's parameters changed) cannot reproduce the attempt: a pass is then a
  replay error (exit 2), never "no longer reproduces". When the replay cannot
  prove the input is the recorded one (another draw count, a repaired slot, a
  token without a buffer), a pass carries a caveat naming why. A recorded
  buffer that no longer produces an input is a replay error (exit 2), and an
  unverifiable replay exits 2. A counterexample that is both unverifiable and
  flaky is reported as unverifiable (exit 2), with the flaky note. A pre-run error
  aborts only its own target; the
  run continues with the other targets and exits 2 at the end (1 if any
  counterexample was found). The exception is a link-time fatal while loading
  a candidate file, which ends the whole run with exit 2 (ADR-0004 item 7).
- Machine-readable output: JUnit XML and JSON.
- Replay: `vendor/bin/pront --replay <token>` (token defined in ADR-0011).
