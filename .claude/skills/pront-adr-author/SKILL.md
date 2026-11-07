---
name: pront-adr-author
description: >-
  Author, amend and wire an Architecture Decision Record under `docs/adr/`. Use when any decision
  (product, scope, architecture, process) needs an ADR, including numbering, index wiring and
  verification.
metadata:
  internal: true
---

# Author an ADR

An ADR records a decision: product and scope (what, for whom, what not), architecture or process.

## The ADR-0008 boundary

`docs/adr/0008-concept.md` is the north star: concept, principles, scope principles and non-goals. It holds **no** technical requirement, concrete decision or version-dependent
content; those go in a later ADR. Changing ADR-0008 is a user decision and an explicit revision.

## Steps

1. **Check the home.** Read the ADRs that constrain the decision. An ADR may refine another but must
   not contradict it; if it must, the earlier ADR changes first (a decision for the user). A principle
   or non-goal belongs in ADR-0008 (a user decision); everything else in a new or amended ADR.
2. **Number it.** A new decision takes the next free four-digit number in `docs/adr/`; file name
   `NNNN-kebab-title.md`. Never renumber or reuse. An amendment of an existing decision edits that
   ADR in place (status stays, change goes through the PR) instead of taking a number.
3. **Write it** with this shape, in English:

   ```markdown
   # ADR-NNNN: Title

   - Status: Proposed
   - Date: YYYY-MM-DD
   - Refines: ADR-NNNN (what it implements)

   ## Context
   The forces and the requirement of the ADR it refines. Apply the ADR-0009 tautology test, the silent-skip
   test (does any unresolvable case neither fail before running nor appear in the report?) and the PHPStan
   vocabulary test (does it invent what PHPStan already names?). Verify every PHP or PHPStan claim by running it, and mark such facts as verified.

   ## Decision
   What we do. Each decision states the rule, its consequence for users, and what is deferred to a
   later ADR; provisional numbers are marked provisional with the owning ADR. A small ADR holds one
   decision; a broad one may hold numbered sections of decisions.

   ## Alternatives rejected
   Table: candidate | reason.

   ## Consequences
   Positive, negative, what becomes harder to change.
   ```

4. **Wire it.** Add a row to `docs/adr/README.md` (the table, and delete the matching line from "ADRs
   still to write"); close or link the issue that requested it. If the ADR supersedes part of the sketch, add a line to
   ADR-0015.
5. **Verify** `make docs-check` through `nix develop --command`.
6. **Review** per `docs/agents/contribution-flow.md`: an ADR is never `Accepted` before an
   independent `pront-reviewer` pass and the user's go-ahead (a new ADR decision is a user decision).
   Status moves `Proposed` → `Accepted, YYYY-MM-DD`.

## Quality bar

Every claim about PHP, PHPStan or a dependency is verified by a script or primary documentation, and
says so. State what would make you reverse the decision. No decision hides in a Consequences line.
