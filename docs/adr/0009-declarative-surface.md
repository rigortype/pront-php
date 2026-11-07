# ADR-0009: Declarative Surface

- Status: Proposed
- Date: 2026-11-07
- Refines: ADR-0008 (Pillars 1, 3, 5)

## 1. Attributes on the implementation
Attributes on the implementation are the primary mechanism. Declaring
properties from the test-class side is a possible future extension, not a
non-goal.

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
  pre-run error: `$__return`, `$__self`, `$__old`. Any other name must be a
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
  declared type statically cannot accept the bound value; a `TypeError` that
  only appears at run time makes the trial *unverifiable* (ADR-0013).
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
checked/unchecked exception configuration is not read). Only methods that
declare
`@throws` are checked (`@throws void` means nothing is thrown): an undeclared
`Exception` is a failure. Methods without `@throws` are not checked for
`Exception`; users are never asked to write `@throws` for Pront's sake.
`Error` subclasses (`TypeError`, `DivisionByZeroError`, `ValueError`, ...)
raised for inputs inside the declared types are failures unless declared in
`@throws`.
