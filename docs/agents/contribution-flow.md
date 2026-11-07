# Contribution flow

Read this before committing, pushing, opening or landing a pull request. Modelled on Rigor's flow,
scaled down: no merge queue, approval queue or release tooling yet (add them when the code and the
contributor count justify it).

## Commits and branches

- Default branch: `master`. Work on a topic branch (`<area>-<short-slug>`). For an isolated checkout
  use the `gw-worktree` skill when you have it; otherwise `git fetch origin && git worktree add
  .claude/worktrees/<branch> -b <branch> origin/master` (that path is git-ignored).
- Commit messages: imperative subject, a body that says why. No attribution trailers.
- Never `--no-verify`, never force-push a shared branch, never rewrite a branch a lane has pushed.
- **Direct push to `master` is allowed for exactly two things**, after `make verify-changed`:
  1. replacing `docs/CURRENT_WORK.md` (the handoff, ADR-0001);
  2. a typo-class fix (a typo, a broken link, a wording fix that changes no meaning) in a file that
     is not on the never-direct-push list.

  **Never direct-push** anything under `docs/adr/`, `docs/agents/`, `.claude/skills/`,
  or the files `AGENTS.md`, `CLAUDE.md`, `GLOSSARY.md`, `Makefile`, `flake.nix`, `bin/*`, and
  nothing that changes a rule (even by one word: must/should, adding "provisional"). Anything
  else lands through a PR. When unsure, use a branch and a PR. (Until `master` has branch
  protection, this is a rule on agents, not a guarantee.)
- Bootstrap: while `origin/master` does not exist, the first push of `master` is the user's call.

## Landing a pull request

1. **Run `make verify-changed`**, then push and open a **Draft PR** that links its issue
   (`Fixes #N` only when it fully fixes it).
2. **Start an independent adversarial review**, in parallel with CI. The reviewer is a fresh agent
   that did not write the change, on the strongest model the harness offers at high effort (under
   Claude Code: Opus, effort high). A harness that cannot start a separate agent says so in the PR and
   leaves it Draft; the review is never skipped silently. The brief gives the base commit, the PR and
   its issue, and the floor below, and tells the agent to load the
   [`pront-reviewer`](../../.claude/skills/pront-reviewer/SKILL.md) skill.
3. **Triage.** Fix correctness defects, contradictions and test gaps; fix wording and nits without
   another round; file scope expansion as issues instead of growing the PR. Answer a rejected finding
   with the reason in a PR comment. Severity is the reviewer's ranking.
4. **Another round only if the last round's fixes addressed a severe defect or changed behaviour.**
   Make it a delta review of the fix commits: re-check each prior finding, hunt for regressions the
   fix introduced.
5. **Stop at three rounds.** If round 3 still finds a severe defect, ask whether the design is
   converging; if not, the PR stays Draft and the user decides. A narrow severe finding with a
   conservative fix (says less, checks more) lands only after one fix-only delta check finds nothing
   severe.
6. **Merge** when all of these hold: `make verify-changed REQUIRE_TESTS=1` passes on the head commit
   after `make setup`, so the tests ran (there is no CI yet; when a CI job exists, it must be green on that commit instead or as well); the review has
   stopped with **no unresolved severe finding**; and the landing point is settled (a
   `ready-for-agent` issue, a user request that states the outcome, or every decision in the PR made
   under "Decisions" below). Bind the merge to the head you are merging: `gh pr ready`, then `gh pr
   merge --squash --match-head-commit <head sha>`. That head is the commit the reviewer reviewed,
   or a later one whose extra commits only fix findings the reviewer classed as Minor, or Moderate
   findings fixed by wording alone (steps 3 and 5): then list those commits in the merge message. Any later commit that
   changes behaviour, edits or deletes a test, or rewords a rule (anything under `docs/adr/`,
   `docs/agents/`, `.claude/skills/`, or `AGENTS.md`, `CLAUDE.md`) gets a delta round
   first. If the user says stop, `gh pr ready --undo`.

**Severe** means: a wrong answer on correct code, a false pass (a gate or property that passes
without checking), a crash, a contradiction with ADR-0008, or guidance that would lead an agent into a
wrong irreversible or gate-skipping action. Unclear wording is not severe.

**ADR changes** follow the same loop, with the ADR as the "change". The reviewer attacks
internal contradictions, feasibility on the pinned PHP version (verify with a script, do not assert
from memory), silent drops of earlier decisions, and unfalsifiable statements.

**The floor every subagent brief carries inline** (a subagent does not inherit `AGENTS.md`): every
command through `nix develop --command`; stdin from `/dev/null`; a reviewer makes no edits, pushes
or comments; nobody but the coordinator merges; ADR-0008 principles bind; project docs are English.

## Decisions

A decision nobody has made is yours to make by precedent when it is small and reversible. **Ask the
user first** for: a new or reversed ADR decision; anything that changes ADR-0008; a release, version bump or publish; repository, organisation or CI
settings; a new runtime dependency; a public
contract (Attribute signature, CLI option, exit code, replay token); skipping the review; a gate or
review rule that checks less (including this section and the skills' contracts); rejecting a severe
finding; or a design still not converging after round 3. State what you decided and why in
the PR body so the user can ratify or revert it.

## Issues

Backlog conventions are in [`issue-tracker.md`](issue-tracker.md) and
[`triage-labels.md`](triage-labels.md). A decision an ADR defers starts as an issue.
