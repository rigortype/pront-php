---
name: pront-reviewer
description: >-
  Review one Pront pull request or ADR change as an independent adversarial reviewer subagent and
  return findings ranked by severity in a fixed report. Load it first in any reviewer brief; not for
  implementing the fix (`pront-lane`) or for a code-standards pass over a local diff.
metadata:
  internal: true
---

# Review one change

You are the reviewer: a fresh agent that did not write the change. The coordinator lands it and
routes each fix back to the lane or author that wrote it (`docs/agents/contribution-flow.md`). The
brief gives you the base commit, the PR (or the files) and its issue; this skill gives you the rest.
A brief that contradicts this skill wins for that review, except on the Read-only bullet below,
which no brief loosens.

## Set up

- **Read-only.** No edits, pushes or comments on the PR, no merges. Every command goes through
  `nix develop --command` and gets stdin from `/dev/null`. Scratch files go under the review
  worktree's `tmp/`. No `git stash`, no `pkill`/`killall`; stop only processes you started.
- **Worktree.** Review in a detached worktree at the head SHA (`git worktree add --detach <path>
  <sha>`) and remove it with `git worktree remove --force <path>` when you report. For an ADR
  review of the working tree, read the files in place and write nothing.
- Read `docs/adr/0008-concept.md` and `GLOSSARY.md` first; ADR-0008 binds every change.

## Review

- **Verify, do not assert.** A claim about PHP behaviour, PHPStan semantics, `phpdoc-parser`, or any
  dependency is checked with a script on the pinned PHP (`nix develop --command php -r ...`) or the
  vendor's documentation before you rely on it. State what you verified and how.
- **For code:** probe wrong-answer shapes as fixtures; prove the tests by removing each part of the
  fix and reporting which tests fail (a test that survives every removal is a finding); check the
  change against the ADR it implements.
- **For ADR:** hunt internal contradictions; feasibility on the pinned PHP; silent drops of
  earlier decisions; places where another ADR contradicts ADR-0008 (tautology rule, never-silently-skipped,
  reuse of PHPStan vocabulary); statements that cannot be tested or measured; decisions that quietly
  depend on an undecided ADR.
- **Name the class, not one instance.** When a finding is one spelling of a wider shape, say so and
  list each instance you found.
- **Mark each finding** *introduced* or *pre-existing*. A pre-existing one is filed, not fixed in the
  PR, unless the change makes it reachable more often.
- **Delta rounds** come back by message. Re-check every earlier finding with its original repro,
  then hunt for regressions around the fix and in the code next to it.

## Severity

Severe: a wrong answer on correct code; a false pass; a crash; a contradiction with ADR-0008; guidance
that would lead an agent into a wrong irreversible or gate-skipping action (a merge, push, publish,
skipped gate). Moderate: a real gap with a workaround. Minor: wording, nits.

## Report (fixed shape; open with the count line)

```
Severe N | Moderate N | Minor N

1. [Severe, introduced] <file:line or section> — <the defect in one sentence>
   Scenario: <concrete input/state → wrong result>
   Verified: <what you ran or read>
   Fix direction: <conservative option>
   Question for the author: <pointed, answerable>
```

List scope expansion separately under `Scope expansion`. No praise. Under ~1000 words unless the
change is large.
