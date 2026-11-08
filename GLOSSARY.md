# Glossary

Domain terms for Pront. Use these words as defined; the *Avoid* lines name drift to resist.

**Pront** — a property-based testing library for PHP that derives generators and oracles from existing
types and verifies contracts declared on the implementation. _Avoid_: "the framework", "the fuzzer".

**Property** — a statement that must hold for all generated inputs of a verified target (an algebraic
law, a contract, a metamorphic relation). _Avoid_: "test case" (a property yields many cases).

**Contract** — the set of conditions a method promises: input types and `Assume`, `Ensures`, return
type, `@throws`, class invariant. A contract declared on an interface or parent is verified against
every implementation in the configured scope (ADR-0012). _Avoid_: "spec", "assertion".

**Verified target** — a concrete method Pront runs trials against. Interface and abstract methods are
*contract sources*, not targets.

**Opt-in** — the explicit `#[Verify]` that tells Pront it may execute a method with generated input.
On an interface or parent method it opts in all implementations in scope (Stage 3).

**Oracle** — the check that decides whether one trial passed. The *implicit oracle* comes from
declared types (return type, `@throws`); explicit oracles come from `Ensures` and the shorthands.

**Generator** — code that produces a value by drawing from the choice buffer. Synthesized from a type,
or registered by the user. _Avoid_: "arbitrary" (QuickCheck term), "set".

**Trial iterator** — the engine API's PHP `Generator` that yields trials (input plus choice buffer) for a
verified target (ADR-0004). _Avoid_: calling it a generator; that word is reserved for value producers.

**Choice buffer** — the recorded sequence of draws behind one trial. Replaying it regenerates the
same input; shrinking edits it (ADR-0011). _Avoid_: "seed" for the buffer (the seed starts a run, the
buffer records one trial).

**Shrinking** — minimizing a failing choice buffer and regenerating, so invariants survive. Pront has
no per-type shrinkers (ADR-0008).

**Counterexample** — the minimal failing input with its choice buffer and, in a report, the seed.

**Edge value** — a boundary or notorious value (`0`, `PHP_INT_MAX`, empty string, NUL byte) mixed in
by default with uniform random values (ADR-0010).

**Discard / rejection** — an input dropped by `Assume` or a failing constructor while generating.
Counted; too many is a configuration error. An implementation's `Assume` rejecting an input the
parent contract allows is a *violation*, not a discard (ADR-0012).

**Pre-run error** — a problem found before any trial runs (an unsupported or contradictory type, a
condition that cannot bind, a misplaced Attribute, an ungeneratable input). Every one is collected and
listed; it aborts only its own target (a link-time fatal while loading ends the run, ADR-0004 item 7)
and makes exit code 2, or 1 if a counterexample was found (ADR-0013). _Avoid_: "warning" (it is never
ignored), "skip".

**Failure signature** — the string that identifies a failure for "same failure": exception class,
the site where the throwable was created and the frames up to the classifier, never the message or
the input (ADR-0011).

**Unverifiable** — a trial whose outcome Pront cannot decide (a `TypeError` while binding a condition
parameter; from Stage 2 a value it cannot compare or snapshot). Counted neither as a pass nor as a
discard, listed, and exit code 2 unless a counterexample was found (ADR-0009, ADR-0013).

**Unchecked** — a return-type fragment that cannot be verified at runtime (a `--strict` item), or an
`Exception` thrown by a method with no `@throws` (counted and listed, not a `--strict` item,
ADR-0009 section 5); reported, never skipped silently (ADR-0009).

**Skipped: unbuildable** — an implementation Pront cannot construct; listed, not failed (ADR-0012).

**Replay token** — what a user passes to `--replay` to reproduce a failure; carries the Pront version
and algorithm id (ADR-0011).

**Stage** — a planned slice of functionality (ADR-0014). Version numbers are assigned as stages ship.

**ADR** — Architecture Decision Record: any decision (product, scope, architecture, process). ADR-0008
is the north star.

**Lane** — one issue implemented by one subagent in its own worktree, returned as a Draft PR.
**Reviewer** — a fresh agent, not the author, that reviews adversarially (see
`docs/agents/contribution-flow.md`).

**Handoff** — `docs/CURRENT_WORK.md`: what the next session should do; replaced wholesale.
