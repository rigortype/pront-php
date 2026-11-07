# ADR-0001: Development-flow document roles

- Status: Proposed
- Date: 2026-11-07
- Refines: none (a process decision); modelled on Rigor ADR-98 (`rigortype/rigor`)

## Context

Working state tends to pile up in whichever markdown file is nearest: a roadmap that keeps shipped
items, a handoff that becomes an append-only log, a changelog that doubles as a plan. Rigor measured
this (its ROADMAP held items shipped months earlier; its handoff reached 75 KB and carried refuted
claims at top priority) and fixed it by giving each kind of knowledge exactly one home, keyed to its
lifetime. Pront starts with that split instead of migrating to it.

## Decision

1. **Backlog: GitHub Issues; release planning: Milestones.** A work item needs a state machine,
   owner, labels and closure on merge; a markdown file has none. Conventions:
   `docs/agents/issue-tracker.md`. There is no `docs/ROADMAP.md`, and none may be created.
2. **Handoff: `docs/CURRENT_WORK.md`.** It answers one question — what should the next session do —
   and is replaced wholesale when work crosses the finish line. Hard cap: 120 lines. Anything that
   outlives two sessions goes elsewhere: backlog → issue, operational pitfall → the workflow's
   skill, any decision → ADR, shipped → `CHANGELOG.md`.
   **Verify a claim before carrying it forward**, by the thing that decides rather than a proxy.
3. **Shipped record: `CHANGELOG.md`** (created with the first release): user-facing summaries of
   user-affecting changes; detail lives in the git log.
4. **Decisions: ADR.** ADRs (`docs/adr/`) hold every decision: product, scope, architecture and
   process. ADR-0008 is the north star and holds principles only (ADR-0008 preamble). Each record carries a `Status:` line.
5. **Gates over instructions.** A budget with no mechanical check is a temporary state. `make
   docs-check` enforces, against git-visible, case-sensitive paths: no file named `ROADMAP.md`;
   `docs/CURRENT_WORK.md` ≤ 120 lines; `AGENTS.md` ≤ 200 lines; every ADR has a `Status:` line
   outside code fences; every ADR has a row in the `docs/adr/README.md` table; relative Markdown
   links (inline, titled, angle-bracketed, reference-style) resolve. It does not check that the
   index's status column matches the file; that remains a review item.

## Alternatives rejected

| Candidate | Reason |
| --- | --- |
| A thin `ROADMAP.md` pointer stub | A stub is a regrowth surface; `AGENTS.md` § "Where the Current State Lives" plus the gate does its job. |
| A medium-term section in `CURRENT_WORK.md` | That is where Rigor's handoff rotted; two homes for one backlog diverge. |
| Local-markdown tracker (`.scratch/`) | The repo lives on GitHub with `gh` in every workflow; a second tracker splits the backlog. |

## Consequences

- Each kind of knowledge has one home; the handoff stays small enough to be read at session start.
- The backlog is not versioned in git and needs network access; issue bodies must be self-contained.
- A recreated ROADMAP or an oversized handoff is a failing `make docs-check`.
