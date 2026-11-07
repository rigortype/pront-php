@AGENTS.md

<!-- The contract lives in AGENTS.md so every agent gets it; the `@AGENTS.md` import above is how
     Claude Code loads it (it reads CLAUDE.md, not AGENTS.md). Add only Claude-specific rules below —
     anything true for every agent belongs in AGENTS.md, or it silently applies to Claude alone. -->

## Claude Code

- [`.claude/skills/`](.claude/skills/) is auto-discovered in this session: contributor workflows
  (`pront-reviewer`, `pront-lane`, `pront-adr-author`). Each `SKILL.md`'s
  `description:` is what routes to it, so keep no catalogue here.
- A subagent does not inherit this contract. A lane or reviewer brief must load the matching skill
  first and carry the floor inline: every command through `nix develop --command`, no pushes or merges
  by a reviewer, and the ADR-0008 principles.
- **Auto memory is clone-wide, not worktree-scoped.** A parallel session in a sibling worktree reads
  and writes the same `MEMORY.md`.
