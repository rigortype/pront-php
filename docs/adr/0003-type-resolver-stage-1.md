# ADR-0003: Type resolver (Stage 1)

- Status: Accepted
- Date: 2026-11-07
- Refines: ADR-0009 section 2, ADR-0014 section 3 (Stage 1)

## Context

ADR-0009 fixes the vocabulary (PHPStan 2.x built-in types) and the rules around it: PHPDoc narrows
native types, a contradiction is a pre-run error, unsupported input syntax is a pre-run error, an
unsupported return fragment is "unchecked". ADR-0014 fixes the Stage 1 type list. The mechanism that
turns PHPDoc into types was left open.

Facts: `phpstan/phpdoc-parser` 2.4.1 returns syntax only (for example `int<0, max>` is a
`GenericTypeNode`, there is no `IntegerRangeTypeNode`) and requires `^7.4 || ^8.0`. PHPStan's
backward-compatibility promise covers only `@api`-tagged classes. In 2.3.5 `Type`,
`IntegerRangeType`, `ConstantIntegerType` and `AccessoryNonEmptyStringType` are `@api`, but
`TypeNodeResolver` and `TypeStringResolver` are not, and PHPDoc-to-type resolution needs them
(checked 2026-11-07).

## Decision

1. **Parse with `phpstan/phpdoc-parser`; resolve with Pront's own limited resolver.** Pront does not
   depend on PHPStan `Type` objects in Stage 1.
2. **Supported types** are exactly the Stage 1 list of ADR-0014 section 3. Any other construct in an
   input position is a pre-run error; in a return position it is "unchecked" except for the
   Stage 1 checked set (`void`, `never`, `null`, `mixed`, `self`, `static`, non-generic class and
   interface names).
3. **Names**: class names resolve with the declaring file's namespace and `use` imports; `self` and
   `static` resolve against the declaring class.
   **Tag precedence** follows PHPStan: for parameters the tags are read in the order `@param`,
   `@phan-param`, `@psalm-param`, `@phpstan-param`, and the last one present wins; for returns the
   order is `@return`, `@phan-return`, `@phan-real-return`, `@psalm-return`, `@phpstan-return`.
   The `@throws` tag is read as written.
   **Pre-run errors in Stage 1** (never silently ignored): `@phpstan-type` / `@psalm-type` aliases and
   `import-type`; integer-range bounds that are not integer literals, `min` or `max` (for example
   `int<1, self::MAX>`); enum case references (`Suit::Hearts`, `Suit::*`) and constant expressions
   other than the literal types of the ADR-0014 list; shapes beyond the ADR-0014 list (optional keys `b?:`,
   unsealed `...`, `list{...}`).
   Only a `@psalm-*` tag that fails to *parse* is skipped, as PHPStan does, and the previous
   tag applies. Any other construct Stage 1 does not support, in whichever tag wins, is a pre-run
   error in an input position and "unchecked" in a return position; Pront never falls back to a
   weaker earlier tag because of an unsupported type.
4. **Narrowing check** (PHPDoc over native type) is implemented for the Stage 1 list only: a PHPDoc
   type must be a subtype of the native type (an integer range inside `int`, a literal inside its
   scalar, a union inside a union, `non-empty-string` inside `string`). A violation is a pre-run
   error naming both types.
   A target declared `never`, or one that ends the process, has undefined behaviour (ADR-0004 item 7).
5. **Runtime checker** for the return-type oracle covers the same set; a failed check is a
   counterexample.
6. **Extensions** (custom PHPDoc types) are not decided here. Stage 1 supports built-in types only;
   the mechanism is a separate ADR for Stage 2.

## Alternatives rejected

| Candidate | Reason |
| --- | --- |
| Use PHPStan `Type` objects via `phpstan/phpstan` | The `Type` classes are `@api`, but the PHPDoc-to-type resolvers are not, and a heavy dependency is imposed on users who may not have PHPStan. |
| Resolve every PHPStan type from day one | Contradicts ADR-0014's fixed Stage 1 list; most of it (templates, conditional types) is not needed to prove the concept. |
| Regex-parse PHPDoc | The syntax is too rich; the parser exists. |

## Consequences

- Stage 1 types outside the list produce clear errors rather than wrong generators.
- The resolver is Pront code, so it must be tested against the Stage 1 list with fixtures.
- Adding types later is additive; the extension ADR may still choose to bridge to PHPStan.
- Revisit if PHPStan makes its PHPDoc resolvers part of the `@api` surface and Pront chooses to depend on it.
- Aliases, constant-expression bounds, enum cases and shapes are candidates for the extension ADR.
