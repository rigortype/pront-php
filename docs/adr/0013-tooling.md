# ADR-0013: Tooling

- Status: Accepted
- Date: 2026-11-07
- Refines: ADR-0008 (scope principles)

## 1. Discovery
Static file analysis (`nikic/php-parser`) over the configured `paths` picks
candidates and builds the parent-to-implementation index. Pront loads only
candidate files directly. Loading a candidate runs its top-level code and
autoloads its parents, interfaces and Attribute classes; `pront.php` is
evaluated; and the code under test may load further files. Pront promises no
more than this.

## 2. Configuration
`pront.php` returns a config object: paths, excludes, default iteration
counts, custom generator and comparator registration, contract registration.

## 3. Runner and reporting
- Primary runner: the CLI `vendor/bin/pront`. A PHPUnit bridge comes later.
- The report ends with a summary line that always shows the counts of skipped
  and unchecked items, reserved-name conflicts (ADR-0009) and "unverified until
  Stage 3" classes (ADR-0012), even when zero.
- Exit codes: 0 success; 1 a counterexample was found (also when other issues
  occurred in the same run); 2 a configuration or unresolved-generator error,
  a runtime "unverifiable" error (a value that cannot be compared or
  snapshotted, ADR-0010, or a `TypeError` raised by a condition, ADR-0009), a
  `paths` setting that matches no `#[Verify]` ("nothing to verify"), or, with
  `--strict`, a skipped implementation, an unchecked return-type fragment, a
  reserved-name conflict or a class "unverified until Stage 3" (ADR-0012).
  Without `--strict`, those items are listed and the exit code stays 0. CI
  should run with `--strict`.
- Other outcomes: a counterexample that does not reproduce (flaky) is reported
  as such and still counts as a counterexample (exit 1); a fatal or time-limit
  error inside a trial counts as a failure (exit 1, with the journaled seed and
  buffer); a `--replay` token from an incompatible Pront version, and any
  pre-run error, give exit 2; a `--replay` that no longer fails reports "no
  longer reproduces" (exit 0), and one whose target no longer exists gives
  exit 2. A pre-run error aborts only its own target; the
  run continues with the other targets and exits 2 at the end (1 if any
  counterexample was found).
- Machine-readable output: JUnit XML and JSON.
- Replay: `vendor/bin/pront --replay <token>` (token defined in ADR-0011).
