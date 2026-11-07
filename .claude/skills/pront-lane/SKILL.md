---
name: pront-lane
description: >-
  Implement one Pront issue as a lane subagent: in its own worktree, returned to the coordinator as a
  Draft PR with a short fixed report. Load it first in any lane brief; not for reviewing a PR (the
  `pront-reviewer` skill) or for coordinating a batch.
metadata:
  internal: true
---

# Implement one issue as a lane

You are a lane: one issue, one worktree, one Draft PR. The coordinator decides, reviews and lands.

## Before you write code

1. Read `AGENTS.md`, `docs/adr/0008-concept.md` and the ADRs the issue cites. Check the premise:
   reproduce or locate the code path on current `origin/master`. If the premise is gone, stop and
   report with the evidence.
2. Create the worktree on a topic branch from `origin/master`: with the `gw-worktree` skill if you
   have it, otherwise `git fetch origin && git worktree add .claude/worktrees/<branch> -b <branch>
   origin/master`. Work only there.

## Rules

- **Environment.** Every command through `nix develop --command`; stdin from `/dev/null`.
- **Scope.** Do what the issue says. Scope expansion becomes a note in your report, not a change.
- **Honest gates.** Never weaken a check, drop an assertion, or reshape code to satisfy a gate. A
  test must fail with the fix removed; say how you checked.
- **ADR-0008 binds.** No annotation whose only purpose is to satisfy Pront; unresolvable situations are
  never silently skipped (they fail before running or are listed in the report); reuse the PHPStan
  vocabulary. A change that needs a new Attribute, public contract or dependency is a decision for
  the coordinator: stop and ask.
- **Claims.** A comment, doc or PR statement is a claim about the code; write only what you
  verified by running it.
- **Git.** Commit with the configured identity, no attribution trailers, no `--no-verify`. Push your
  branch and open a **Draft** PR that links the issue. Never `gh pr ready`, never merge, never edit
  `CHANGELOG.md` or bump versions.
- **Decisions.** If the issue left a choice open, take the smallest reversible option, and list it
  under `Decided` in the report.

## Gate

Run `make verify-changed` before pushing, plus the tests you wrote.

## Hand-back report (fixed shape, short)

```
PR: <url>
Done: <one line>
Verified: <commands run and what they showed>
Decided: <choices you made, or none>
Known limits: <what is not covered, one per line, or none>
Scope expansion: <issues worth filing, or none>
Open: <anything left uncommitted or undone, or none>
```
