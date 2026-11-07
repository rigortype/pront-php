# ADR-0004: Execution model (Stage 1)

- Status: Accepted
- Date: 2026-11-07
- Refines: ADR-0011 section 2, ADR-0013 section 3, ADR-0014 section 3 (Stage 1)

## Context

ADR-0011 section 2 requires that a fatal error (memory or time limit) must not lose reproducibility,
that seed and buffer are journaled before each trial, and that `pront.php` is re-evaluated wherever
trials run. It leaves the mechanism to an ADR. The first draft of this decision was a worker process
per method; a simpler shape suffices for Stage 1.

Facts verified on PHP 8.5.12: functions registered with `register_shutdown_function` run after a
memory-limit fatal and after a time-limit fatal, and `error_get_last()` then reports the error. PHP
exits with status 255 after a fatal unless a shutdown function sets another status, and shutdown
functions also run after `exit` and `die`.

## Decision

1. **Engine API**: the core exposes an iterator of trials (a PHP `Generator`; called the *trial
   iterator* here to keep it apart from the glossary's generator, which draws values) for a verified
   target. Each trial holds the generated input and its choice buffer; running a trial and reporting
   its outcome is the caller's job. The CLI and a later PHPUnit bridge share this API.
2. **Stage 1 runs in-process.** The CLI runs trials in its own process. No worker process,
   `proc_open`, per-trial timeout or watchdog exists in Stage 1.
3. **Run start.** The CLI prints the run seed and the algorithm identifier (ADR-0011) on its first line,
   so a log of a failing run always carries them.
4. **Trial seeds.** Each *fresh trial* number `k` of a target (counting every fresh trial of that target, discarded ones
   included; shrink candidates and flaky re-runs do not consume `k`, they carry their recorded buffer)
   has its own PRNG stream, seeded from the target's seed and `k`. The target's seed is
   `#[Verify(seed:)]` when set, otherwise derived from the run seed and the target name. The stream is by a fixed derivation named by the algorithm
   identifier. `k` is scoped to one target, so one target's shrinking never changes another's inputs. A fresh trial is therefore regenerated from (seed, k) alone, without re-running earlier
   trials' user code.
5. **Journal.** Each run writes its own file, `.pront/runs/<run-id>.jsonl`, and holds a `flock` on it
   for the run's life; nothing is truncated. Records are appended and flushed:
   - `begin`: written **before any user code runs for that attempt**, including the constructors and
     destructors reached while generating the input. It holds the target, the seed, `k` and the full
     choice buffer when the attempt is a shrink candidate or a flaky re-run.
   - `end`: written when the attempt, including the release of its input, has finished.
   - `fatal`: written best-effort by the shutdown function (below).
   - `done`: written when the run finishes normally.
   Every candidate evaluation of the shrinker and every flaky re-run is an attempt with its own
   `begin` and `end`. At run start, files not locked by a live run are rotated: the newest 20 are
   kept; only files that are `done`, already reported, or hold no attempt are deleted.
6. **Shutdown function.** The runner registers one shutdown function at run start. If an attempt is
   in flight (a `begin` without an `end`), it first raises the limit with
   `ini_set('memory_limit', '-1')` (so it works after memory exhaustion), then records the `fatal`
   record, using `error_get_last()` only for a fatal-type error (the runner calls `error_clear_last()`
   before each attempt), writes the replay token and the message to STDERR, and ends the process with
   exit code **1**. This covers memory and time-limit fatals (PHP would otherwise exit with 255).
7. **Undefined behaviour.** Code under test that ends the process (`exit`, `die`), registers its own
   shutdown functions, or lives in a function or method declared `never` is outside the contract:
   the result is undefined (it may report a pass, a crash or nothing). Pront does not try to detect
   it. Destructors of objects retained by static state also run after every shutdown function.
8. **Crash recovery.** At run start, Pront scans only run files not locked by a live run. A `begin`
   without an `end` or a `fatal` is printed once with its replay token and then marked `reported`
   while holding the file's lock, so two runs starting together do not both print it (a crash that its own run already reported with exit 1 is not printed again). Nothing is re-run
   automatically. `--replay <token>` re-runs it. A process killed by SIGKILL or SIGTERM (a CI
   timeout) leaves only the `begin` record, which is enough. Signal handlers are not a Stage 1
   feature.
9. **Replay limits.** A replay token reproduces the *Pront-side* inputs of one attempt. Replaying
   trial k alone does not reproduce static or global state that trials 1 to k-1 of the original run
   left behind, so a failure that depends on such state may not reproduce; the report says so when a
   replay passes.
10. **Isolation is deferred.** A worker-process mode (`--isolate`: a process per method, JSON Lines
   over pipes, a parent-side timeout, crashes counted as failures) is a separate ADR, not yet
   scheduled to a release stage (ADR-0014 does not list it).
11. **PHPUnit bridge is deferred.** Its shape is recorded here: one generic test class whose
   `dataProvider` yields the discovered targets (`yield from`), and whose test body calls the
   engine's verify-and-shrink loop for that target. A `dataProvider` alone cannot shrink, because it
   only supplies inputs and PHPUnit runs each test once. ADR-0014 lists it under "Later".

## Alternatives rejected

| Candidate | Reason |
| --- | --- |
| Worker process per method in Stage 1 | More machinery than a proof of concept needs; the journal already preserves reproducibility. |
| `pcntl_fork` | Unavailable on Windows. |
| PHPUnit `dataProvider` as the only front end | Cannot shrink; the contracts live on the implementation, not in a test class (ADR-0008, Pillar 1). |

## Consequences

- An infinite loop or a hard crash in the code under test stops the whole run in Stage 1; the journal
  makes it reproducible, and users can wrap the CLI in `timeout`. Documentation says so.
- Fatal errors after which the PHP process cannot run shutdown functions (a segfault, SIGKILL) leave
  only the `begin` record; that still identifies the attempt.
- A wall-time limit cannot preempt code that never returns; only `timeout` around the CLI can.
- The engine API is the long-term seam: `--isolate` and the PHPUnit bridge are additions, not rewrites.
- Follow-up issues: the `--isolate` ADR and the PHPUnit bridge ADR, both unscheduled.
