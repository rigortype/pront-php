# ADR-0005: Runtime dependencies and versions

- Status: Accepted
- Date: 2026-11-07
- Refines: ADR-0009 section 2, ADR-0013 section 1, ADR-0014 section 1

## Context

ADR-0013 uses a PHP parser for discovery and the parent-to-implementation index; ADR-0009 uses a PHPDoc
parser. `composer.json` currently requires only `php`. A new runtime dependency is an ask-first
decision (`docs/agents/contribution-flow.md`).

Facts (packagist, 2026-11-07): `phpstan/phpdoc-parser` 2.4.1 (`php ^7.4 || ^8.0`), `nikic/php-parser`
5.9.0 (`php >=7.4`), `phpstan/phpstan` 2.3.5. All allow a PHP 8.5 floor. The original sketch pinned
`phpdoc-parser ^1.25`, which is out of date.

## Decision

1. Runtime requirements are exactly:
   - `php`: `>=8.5`
   - `phpstan/phpdoc-parser`: `^2.4`
   - `nikic/php-parser`: `^5.9`
2. Pront does not require `phpstan/phpstan`. It works in projects where PHPStan is not installed;
   PHPStan is the source of the *vocabulary*, not a runtime dependency (ADR-0003).
3. `symfony/expression-language` from the sketch is not used (ADR-0009: conditions are PHP callables).
4. Required extensions are `ext-tokenizer` and `ext-json` (both are required by `nikic/php-parser`
   5.9.0). No other extension is required. `ext-mbstring` and `ext-intl` are optional and used only
   by test fixtures and comparators when present; code that needs them declares them in `suggest`.
5. Caret ranges are used; a new major of either library is adopted by a deliberate change.
6. Development dependencies: Pront's own tests run on PHPUnit `^13.4` in `require-dev` (amended
   2026-11-08 with the first test, issue #15). It is the newest stable major; 13.4.6 was verified
   installing and running on PHP 8.5.12 (it requires PHP `>=8.4.1`). It is dev-only, so users never
   install it. A later major is adopted by amending this item. Its dependency chain requires
   further extensions for development only (among them `ext-mbstring` and `ext-dom`, per the
   lock file), so Pront's own suite always runs with mbstring loaded; the mbstring-absent path of
   item 4 needs a separate check once code depends on it.
7. `composer.json` and `composer.lock` are updated in the first Stage 1 implementation change, not by
   this ADR.

## Alternatives rejected

| Candidate | Reason |
| --- | --- |
| Depend on `phpstan/phpstan` | See ADR-0003; heavy, and its PHPDoc resolvers are not `@api`. |
| Write a PHPDoc/PHP parser | Needless; both libraries are the standard tools. |
| Pin exact versions | Blocks users' resolvers for no benefit before 1.0. |
| Pest, or a custom runner, for Pront's own tests | Pest is a layer over PHPUnit that adds dependencies; a custom runner is code to maintain. |

## Consequences

- Two small, widely used dependencies; supply-chain surface is limited to them.
- A breaking change in either parser's AST affects discovery or type parsing; fixtures must cover
  the node types Pront reads.
- Revisit if a Stage 2 need (for example custom PHPDoc types) justifies another dependency.
