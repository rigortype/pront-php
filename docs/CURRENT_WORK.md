<!--
The session handoff (ADR-0001). It answers ONE question: what should the next session do?

- REPLACE this file's content when you take work across the finish line; never append under it.
  Anything that would outlive two sessions does not belong here: backlog → a GitHub issue,
  decisions → an ADR, shipped → CHANGELOG.md.
- Hard cap: 120 lines, enforced by `make docs-check`. Compress, do not append.
- Verify a claim before carrying it forward, by the thing that decides rather than a proxy.
-->

# Current Work — Session Handoff

Transient; replaced wholesale. Backlog lives in GitHub Issues, release planning in Milestones.
If this file disagrees with an ADR or an issue, this file is the one that is wrong.

## State at 2026-11-09

- **Stage 1 is implemented and merged** (issues #15-#23, PRs #14, #24-#33): skeleton, choice buffer,
  type resolver, generators, shrinker (`pront-1`), attributes and discovery, engine and journal,
  oracles, CLI (exit codes 0/1/2). The ADR amendments that came out of it are merged (PR #43);
  all ADRs (0001-0015) are Accepted.
- The development flow is in place (AGENTS.md, `docs/agents/`, `pront-*` skills, Flake,
  `make verify-changed`). There is no CI and no branch protection.
- Decided during the amendments: `pront-2` (length split, always record the uniform draw, pass 0
  "Edge re-encoding"), NotReproduced means FLAKY, edge ratio only via `#[Verify(edgeRatio:)]`,
  by-reference target parameters are a pre-run error, an unverifiable trial does not end a target's
  trials and a real counterexample wins (exit 1). `exit`/`die`/`never` targets stay undefined.
- Known and accepted: a string edge reached late in shrinking can stay at the edge (for example
  `" "` instead of `"a"`); ADR-0002 records the alternative (protect an edge's unused draws).

## Next

1. Spec-to-code follow-ups, `ready-for-agent` unless noted: #34 (`pront-2`), #36, #37, #38, #40,
   #41, #42, #44. #35 and #39 are `needs-triage`; #39 (Pront's own paths in failure signatures)
   has no decided design, so ask the user first.
2. User: approve #7 (CI job) and #8 (branch protection); both are repository settings.
3. Stage 2 and unscheduled ADRs: #1-#3, #11 (Stage 2), #4-#6, #12-#13; known Minors #9-#10.
4. Optional cleanup, user's decision: old local branches `old-master`, `adr-unify`, `adrs-stage1`,
   `fresh-history`, `dev-flow-r3`, `pdr-fixes` (not merged into master).
