# ADR-0003: Type resolver (Stage 1)

- Status: Accepted
- Date: 2026-11-07
- Amended: 2026-11-08 (Stage 1 findings: `@throws` family, the unparsable-tag rule, `$this` and
  `object` returns, PHPDoc inheritance, and the readings and extra pre-run errors of the resolver)
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
   interface names). `$this` and `object` are outside the checked set and therefore "unchecked".
3. **Names**: class names resolve with the declaring file's namespace and `use` imports; `self` and
   `static` resolve against the declaring class.
   **Tag precedence** follows PHPStan: for parameters the tags are read in the order `@param`,
   `@phan-param`, `@psalm-param`, `@phpstan-param`, and the last one present wins; for returns the
   order is `@return`, `@phan-return`, `@phan-real-return`, `@psalm-return`, `@phpstan-return`.
   `@throws` follows PHPStan (ADR-0009 section 5): `@phpstan-throws` is read when present, otherwise
   `@throws`; the class names resolve like other names (`self`, `static` and `parent` included) and
   must name `Throwable` classes.
   **Pre-run errors in Stage 1** (never silently ignored): `@phpstan-type` / `@psalm-type` aliases and
   `import-type`; integer-range bounds that are not integer literals, `min` or `max` (for example
   `int<1, self::MAX>`); enum case references (`Suit::Hearts`, `Suit::*`) and constant expressions
   other than the literal types of the ADR-0014 list; shapes beyond the ADR-0014 list (optional keys `b?:`,
   unsealed `...`, `list{...}`).
   **Unparsable and unresolvable tags.** PHPStan drops a tag that fails to parse, of every family,
   from type resolution (`PhpDocNode::get*TagValues()` keeps only parsed tag values), reports invalid
   tags other than `@psalm-*` and `@phan-*` through a separate rule, and skips a `@psalm-*` tag whose
   type it cannot resolve (`shouldSkipType`). Verified 2026-11-08 in phpstan-src 2.3.5,
   `src/PhpDoc/PhpDocNodeResolver.php` (`resolveParamTags`, `resolveReturnTag`, `resolveThrowsTags`,
   `shouldSkipType`) and `src/Rules/PhpDoc/InvalidPhpDocTagValueRule.php`, and phpdoc-parser 2.4.1
   `PhpDocNode::getParamTagValues()`. Pront is stricter: a `@psalm-*` tag that fails to
   *parse* is skipped and the previous tag applies; a tag of any other family that fails to parse is
   a pre-run error; a `@psalm-*` tag that parses but names a type Stage 1 cannot resolve is not
   skipped. Any construct Stage 1 does not support, in whichever tag wins, is a pre-run error in an
   input position and "unchecked" in a return position; Pront never falls back to a weaker earlier
   tag because of an unsupported type.
   **Readings** (as PHPStan reads them): `T[]` and `array<V>` mean `array<int|string, V>`, and
   `array-key` means `int|string`; `integer`, `boolean` and `double` are synonyms of `int`, `bool` and
   `float`, and `noreturn`, `never-return`, `never-returns` and `no-return` of `never`; other PHPStan
   2.x keywords are recognised as unsupported, never as class names. A shape key is normalised as PHP
   does (`'1'` is the int key `1`; `'01'` and `'-0'` stay strings). PHPDoc narrows as PHPStan's
   `TypehintHelper::decideType` does: native union members disjoint from the PHPDoc type stay (so
   `?int` with `@param positive-int` is `int<1, max>|null`), and a parameter whose default is `null`
   also accepts `null`. An alias (`@phpstan-type`, `@psalm-type`, `@phan-type`, `import-type`) is an
   error where a type uses it, not where it is declared.
   **Further pre-run errors in Stage 1:** an integer literal written in a form PHP and PHPStan read
   differently (PHPStan 2.x casts with `(int)`, so it reads `0x1F`, `0b11` and `0o17` as 0 and `010`
   as 10; the message gives both readings and asks for decimal; a literal both read alike, such as `00`
   or `0x0`, is accepted); a lone `null` as an input type (the list has `?T`, not `null`; `null` is
   accepted inside a union); an empty range (`int<5, 1>`); an integer literal outside PHP's int range;
   a duplicate shape key; an enum with no cases anywhere in an input type; a `@param` naming a
   parameter that does not exist; a variadic parameter; a parameter with no type at all; `@throws`
   naming a non-`Throwable` class; `@throws void` combined with classes; a parameter default
   expression that contains `new` other than at its start (it is never evaluated); class types,
   `self`, `static`, `mixed`, `void` and `never` in an input position ("until Stage 2" for the
   class-like ones); a native member whose overlap with the PHPDoc type Stage 1 cannot decide (for
   example native `callable`), which in a return position makes the return "unchecked" instead.
   **PHPDoc inheritance** is not implemented in Stage 1. PHPStan inherits `@param`, `@return` and
   `@throws` PHPDoc from parent, interface and abstract trait methods for a slot the method does not
   declare; where that inherited type would differ from the native type (or Pront cannot decide
   whether it would), the method is a pre-run error naming the slot, instead of being verified against
   a type PHPStan would not use. Pront checks every ancestor, so it can report where PHPStan, which
   stops at the first source with a docblock, inherits nothing; that direction is conservative.
   Ancestor PHPDoc that only restates the native type is no error. Stage 3 contract inheritance
   (ADR-0012) lifts this restriction.
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
