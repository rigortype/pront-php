<!--
Maintainer notes. Block-level HTML comments are stripped before this file enters an agent's context.

- Claude Code reads CLAUDE.md, not AGENTS.md; CLAUDE.md imports this file.
- This file is loaded in every session. Keep it under ~200 lines (`make docs-check` enforces it) and
  put conditional detail behind a pointer.
- The "Agent skills" section was written by the setup-matt-pocock-skills skill, which targets
  CLAUDE.md when that exists. CLAUDE.md here only imports this file, so do not re-run that skill
  without moving its output into this section; keep a single copy.
-->

# AGENTS.md

Agent contract for Pront. Project-authored docs are written in English.

## Operating rules

Read the pointer that matches the task; a typo fix does not require a repository tour. For a change
request, finish at the observable outcome requested, including its matching validation and handoff,
rather than stopping at the first plausible edit.

## Development Environment

Run every development command through the Nix Flake:

```sh
nix develop --command <cmd>
```

Nix must have the `nix-command` and `flakes` experimental features enabled (the Determinate Systems
installer enables both). The Flake owns PHP 8.5 and Composer. `make setup` is the first-time setup.
For isolated work, use the `gw-worktree` skill, or the `git worktree` fallback in the contribution
flow. Give any command an agent runs stdin from
`/dev/null`: an agent shell's stdin never closes, so a command that reads it hangs.

## Validation

- Before pushing: `make verify-changed` (whitespace and `make docs-check`; it grows with the code).
- A direct push to `master` (a typo-class fix, or replacing `docs/CURRENT_WORK.md`; see the
  contribution flow for what qualifies) must pass `make verify-changed` first.
- Fix the cause of a failing gate instead of weakening it.

## Contribution flow

Before committing, pushing, opening a PR, or landing one, read
[`docs/agents/contribution-flow.md`](docs/agents/contribution-flow.md). In short: topic branch, Draft
PR, independent adversarial review, at most three rounds. Only replacing `docs/CURRENT_WORK.md` and
typo-class fixes outside governance files go straight to `master`; ADRs, agent docs, skills
and build files never skip the review.

## Design rules

- [`docs/adr/0008-concept.md`](docs/adr/0008-concept.md) is the north star. Decisions of
  every kind live in ADRs (`docs/adr/`). ADR-0008 holds principles only; technical and version-dependent content belongs in other ADRs.
- Pront reuses PHPStan's type vocabulary (ADR-0008, scope principles) and never requires annotations
  whose only purpose is to satisfy the tool (ADR-0008, Pillar 5). Check a new Attribute against the
  tautology test in ADR-0009.
- Unresolvable situations are never silently skipped: they fail before running or are listed in the
  run's report (ADR-0008, scope principles).
- Check [`GLOSSARY.md`](GLOSSARY.md) before using a domain term.
- `docs/sketch.md` is the original hand-off draft; where it disagrees with the ADRs, the ADRs win
  (ADR-0015).

## Where the Current State Lives

- [`docs/CURRENT_WORK.md`](docs/CURRENT_WORK.md) is the transient session handoff; replace it
  wholesale (ADR-0001).
- GitHub Issues hold the backlog for `rigortype/pront-php`; Milestones hold release planning. Use
  [`docs/agents/issue-tracker.md`](docs/agents/issue-tracker.md) for issue and triage conventions.
- [`GLOSSARY.md`](GLOSSARY.md) is the domain glossary. `CHANGELOG.md` (once it exists) is the
  user-facing shipped record. [`docs/adr/README.md`](docs/adr/README.md) is the ADR index.

## Agent skills

### Issue tracker

Issues live in GitHub Issues for `rigortype/pront-php` (via the `gh` CLI). See `docs/agents/issue-tracker.md`.

### Triage labels

Default five-label vocabulary (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `GLOSSARY.md` + `docs/adr/` at the repo root. See `docs/agents/domain.md`.
