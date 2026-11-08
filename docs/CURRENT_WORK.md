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

## State at 2026-11-08

- No code yet. The former PDR series is now ADR-0008..0015 (PDR-N became ADR-(N+8), `git mv`'d from
  `docs/pdr/`; there is one series). All ADRs (0001-0015) are **Accepted**.
- The development flow is in place (AGENTS.md, `docs/agents/`, three `pront-*` skills, Flake,
  `make verify-changed`). There is no CI and no branch protection.
- Open backlog (GitHub issues): #1-#3 and #11 (Stage 2 ADRs), #4-#5 and #12-#13 (unscheduled ADRs),
  #6 (v0.1.0 stability ADR), #7 CI, #8 branch protection, #9-#10 known Minors.
- Decided on the way, worth knowing: code under test that calls `exit`/`die`, or a `never` target,
  has undefined behaviour (ADR-0004). Non-small edge values such as `PHP_INT_MAX` are minimal by
  design under shortlex and can be reported instead of a smaller failing value (ADR-0002).

## Next

1. User: approve #7 (CI job) and #8 (branch protection); both are repository settings.
2. Stage 1 is split into issues #15-#23 (milestone `Stage 1`, label `ready-for-agent`): #15 skeleton
   and dependencies first (a dev test runner is an ask-first dependency), then #16 choice buffer,
   #17 resolver, #18 generators, #19 shrinker, #20 attributes and discovery, #21 engine and journal,
   #22 oracles, #23 CLI. Run each as a `pront-lane` lane, one at a time on shared files.
