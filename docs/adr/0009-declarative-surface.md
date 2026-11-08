# ADR-0009: Declarative Surface

- Status: Accepted
- Date: 2026-11-07
- Amended: 2026-11-08 (Stage 1 findings: Attribute placement errors, reserved prefixed names, the
  "cannot accept" check, "unverifiable", the return oracle's acceptance, unchecked exceptions;
  by-reference target parameters are a Stage 1 pre-run error; an unverifiable trial does not end the
  target's trials)
- Refines: ADR-0008 (Pillars 1, 3, 5)

## 1. Attributes on the implementation
Attributes on the implementation are the primary mechanism. Declaring
properties from the test-class side is a possible future extension, not a
non-goal.

A Pront Attribute on a trait method (reported once, on the trait), a
`#[Verify]` on a non-public method, and one on a constructor or destructor are
pre-run errors.

## 2. Type vocabulary
- The vocabulary is PHPStan's built-in types. Where it needs extending, the
  extension is made explicit, in the manner of PHPStan's custom PHPDoc types
  (<https://phpstan.org/developing-extensions/custom-phpdoc-types>). Whether
  extensions are obtained via PHPStan, interpreted by Pront, or declared
  separately for Pront is deferred to an ADR.
- Stage 1's vocabulary is the built-in types of PHPStan 2.x (the major current
  when this ADR was written); adopting a later major is an amendment of this ADR. The
  subset each stage implements is fixed in ADR-0014.
- Input constraints expressible in that vocabulary are written as types. Pront
  does not add duplicating Attributes.
- Type precedence: PHPDoc narrows native types. A contradiction is a pre-run
  error.
- Unsupported type syntax in an input position is a pre-run error. A
  return-type fragment that cannot be checked at runtime (for example
  `callable(int): string`, `Collection<int>`) is reported as "unchecked",
  never silently skipped. A fragment is unchecked when the shipped stage has
  no runtime check for it; ADR-0014 fixes the checked set per stage (Stage 1:
  the input types, `void`, `never`, `null`, `mixed`, `self`, `static` and
  non-generic class or interface names).
- The return-type oracle decides with PHPStan's `accepts` under strict types,
  not `isSuperTypeOf`: `float` accepts an `int` (as PHP widens it), an
  integral float literal type (`1.0`) accepts the equal `int`, and a
  `Stringable` is not a `string`, even when the target's file is not in strict
  mode.

## 3. Tautology test and catalog audit
An Attribute that restates what types, language features or PHPDoc tags
already say is a violation. Declarations of test intent (`#[Verify]`,
`#[Cover]`, `#[Assume]`) are not. Consequences for the sketch's catalog:

- `#[Gen\IntRange]` is removed.
- `#[OnlyThrows]` / `#[NeverThrows]` are removed; `@throws` is the implicit
  oracle (section 5).
- `#[Gen\HostileStrings]` is removed (ADR-0010, edge values).
- `#[Immutable]` is kept only if it adds information beyond `@phpstan-pure` and
  `readonly`; otherwise it becomes implicit (decided in an ADR).
- `#[Gen\Sorted]` is kept as an Attribute: element order is not expressible in
  PHPStan's vocabulary.
- Dynamic bounds (formerly `IntRange('count($items)')`) are expressed by one
  general dependent-constraint Attribute taking a condition; name and syntax
  are left to an ADR.

## 4. Conditions
Conditions are PHP callables usable in Attribute arguments, in these forms:

- **Primary: `static function` closures** (PHP 8.5). Arrow functions are not
  allowed in constant expressions; `$this` cannot be a parameter name.

      #[Ensures(static function (int $return, int $price): bool {
          return $return >= $price;
      })]

- **Secondary: static method references** (`[Checks::class, 'atLeast']`),
  which compile on older PHP versions.

- **Secondary, PHP 8.5 only: first-class callables** (`Checks::atLeast(...)`);
  their parameter names come from the target, so reusable predicates must use
  matching names.

Using the closure form makes the production code itself require PHP 8.5 (the
compile error occurs at file compilation, not lazily). This is a stated
consequence; first-class callable syntax in an Attribute is assumed to be
8.5-only as well (not verified below 8.5). Code using only static method
references can be verified by Pront on PHP 8.5 in CI while production supports
older versions.

Binding rules:
- Parameters bind **by name**. Reserved names: `$return`, `$self` (the
  receiver), `$old`; each is also available as a prefixed alias that is never
  ambiguous unless a method parameter has the same prefixed name, which is a
  pre-run error on a method that carries an `#[Ensures]` (on any other method
  it is an ordinary parameter name, as is any other `$__name`): `$__return`,
  `$__self`, `$__old`. Any other name must be a
  parameter of the method that carries the Attribute, otherwise it is a
  pre-run error. If the method has a parameter named like a reserved name, the
  unprefixed name in a condition binds to that method parameter and the
  reserved meaning is reached through the prefixed alias. A conflict is
  counted in the report's summary line and, with `--strict`, makes exit code 2
  (ADR-0013) when the bare reserved name is referenced on such a method by any
  condition other than a closure literal written in that Attribute's own
  argument (a first-class callable, a static method reference, a closure held
  in a constant or variable), or by a condition parameter that is untyped or
  `mixed`, because it would otherwise change meaning silently. Referencing `$return` or
  `$__return` on a `void` or `never` method, and `$self`, `$old` or their
  aliases on a static method, is a pre-run error.
  So is a condition parameter that is by-reference, variadic, or whose
  declared type statically cannot accept the bound value. "Cannot accept" is
  decided on the condition parameter's native type only, in strict mode (an
  `int` widens to `float`); a type Stage 1 cannot model, or an overlap it
  cannot decide, is not a pre-run error. A `TypeError` that only appears at run
  time makes the trial *unverifiable* (ADR-0013). Only the `TypeError` raised
  while binding a value to a condition parameter is unverifiable; a `TypeError`
  from code the condition calls, or from the condition's own return type, is a
  failure of the condition (a counterexample). An unverifiable trial does not
  end the target's trials: it is recorded (counted, with the first one's
  replay token) and counts toward `iterations`, and the remaining trials still
  search for a real counterexample. A real counterexample takes precedence:
  it is the one shrunk and reported, and the unverifiable trials are listed
  beside it (exit 1, ADR-0013). Only when the target's trials found no real
  counterexample is the target reported unverifiable (its first unverifiable
  trial shrunk and reported, exit 2 unless another target found a
  counterexample). Shrinking an unverifiable trial accepts only candidates with
  the same failure signature (ADR-0011), so a candidate that turns into a real
  counterexample is not accepted. The rule covers every kind of unverifiable
  trial, including the comparison and snapshot cases of ADR-0010 section 3. Stage 1 as merged still ends the
  target's trials at the first unverifiable trial; this follows in #38.
- Conditions bind the generated argument values, before the call. A target
  parameter passed by reference is a pre-run error in Stage 1 (a condition
  would see the pre-call value even when the method changed it); post-call
  binding is decided with the `$old` snapshot (#1). Stage 1 as merged does not
  reject it yet; this follows in #41.
- Names bind against the method that carries the Attribute. When the contract
  is applied to an implementation whose parameters are renamed, the parent's
  parameters are mapped to the implementation's by **position**.
- Multiple parents: contracts are combined with AND; duplicates in diamond
  inheritance are removed, keyed on declaring class, method and Attribute
  index (closure identity cannot be used: reflection returns a fresh Closure
  on each call).
- The argument dependency DAG is derived from condition parameter names.
- No string expression language; `symfony/expression-language` is not used.
- `$old` is a deep snapshot (same traversal as equality, ADR-0010), not a shallow
  `clone`.

## 5. `@throws`
`@throws` follows PHPStan's `@throws` semantics (PHPStan's own
checked/unchecked exception configuration is not read). The tag is
`@phpstan-throws` when present, otherwise `@throws` (ADR-0003). Only methods
that declare `@throws` are checked (`@throws void` declares nothing, so every
throwable fails): an undeclared `Exception` is a failure, and a declared one
passes (the return type and `Ensures` are then not evaluated). Methods without
`@throws` are not checked for `Exception`: such a trial passes, and the
exception is counted per target and listed in the report as "unchecked" (never
a silent pass, and not a discard, which could trip the discard threshold);
`--strict` does not turn it into exit 2 (ADR-0013). Users are never asked to
write `@throws` for Pront's sake.
`Error` subclasses (`TypeError`, `DivisionByZeroError`, `ValueError`, ...)
raised for inputs inside the declared types are failures unless declared in
`@throws`.
