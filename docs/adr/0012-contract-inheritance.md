# ADR-0012: Contract Inheritance (LSP Verification)

- Status: Accepted
- Date: 2026-11-07
- Amended: 2026-11-08 (Stage 1 finding: how class chains outside `paths` are followed)
- Refines: ADR-0008 (Pillar 6)

Contracts on interfaces and parent methods apply to every implementation or
override in scope. PHP Attributes are not inherited, so Pront does this itself.

## 1. Participation and scope
- The party that declares the contract opts in: `#[Verify]` on an interface or
  parent method means "verify this contract against all implementations in
  scope". The
  implementation's author need not annotate.
- Implementations are searched only inside the `paths` of `pront.php`;
  `vendor/` is excluded by default. A contract from outside the scope is
  registered explicitly in `pront.php`. Test doubles are excluded with
  `exclude` patterns (for example `tests/**`).
- An implementation that cannot be built (for example interface-typed
  constructor dependencies) is listed as "skipped: unbuildable" and does not
  fail the run (the report's summary line always shows the count); with
  `--strict`, skipped implementations make exit code 2 (ADR-0013). Users resolve
  them with custom generators.
- Verified targets are concrete methods; interface and abstract methods are
  valid *sources* of contracts.
- The guarantee is therefore "every implementation in the configured scope".
  Anonymous classes and classes created with `eval` are out of scope: they
  cannot be constructed by type, and `eval`'d code is not seen by static
  discovery.
- A contract (`Ensures`, `Assume`, ...) on a method that has no `#[Verify]` on
  itself or on any ancestor method is a pre-run error: a dangling contract
  would otherwise verify nothing, silently.
- Until Stage 3 ships (ADR-0014), these are pre-run errors ("unsupported until
  Stage 3"): a `#[Verify]` or contract on an interface or abstract method; one
  on a method whose parent method carries a contract or `#[Verify]`; and a
  class in scope that overrides or implements, directly or through a trait
  (trait methods are resolved when the index is built), a method carrying a
  `#[Verify]` or contract. A method that merely implements an interface method
  with no contract (for example `__toString` or `count`) is unaffected. A
  `#[Verify]` that matches zero targets (for example an interface with no
  implementation in scope) is a pre-run error.
- "In scope" is followed outside `paths` only through the project's
  autoloader: for a class in scope whose parent chain leaves the index, its
  first out-of-index ancestors are autoloaded; an ancestor that only a manual
  `require` provides is not followed. A class whose chain leaves `paths`
  through an ancestor that cannot be autoloaded (or whose autoloader throws)
  is listed as "unverified until Stage 3" with that reason, counted on the
  summary line, and a `--strict` item, never dropped silently. Stage 1 as
  merged does not list it yet; this follows in #44. An override made by an
  out-of-`paths` intermediate class is reported on no class; its in-scope
  subclass is only listed as "unverified until Stage 3" (below).
- Some uses cannot be seen statically before Stage 3 (for example a subclass
  that changes behaviour only through a method the verified method calls).
  Every class in scope that extends, directly or transitively, a class
  carrying a `#[Verify]` or contract method is therefore listed as
  "unverified until Stage 3", counted on the summary line and, with
  `--strict`, makes exit code 2 (ADR-0013). A class already reported as a pre-run
  error above is not listed a second time. Pront does not promise more than
  this before Stage 3.

## 2. Violation rules
1. **Preconditions are not strengthened.** Inputs are generated from the
   parent's contract (types, `Assume`) and given to each implementation. If an
   implementation's `Assume` rejects such an input, or it throws unexpectedly,
   that is a violation (not a discard in the sense of ADR-0010). An implementation
   narrowing a parameter through
   PHPDoc (parent `int`, implementation `int<0, max>`) also counts.
2. **Postconditions are not weakened.** The parent's `Ensures` and return-type
   oracle apply to every implementation's result. Implementations may add
   `Ensures`.
3. **Exceptions are not widened.** An implementation may throw only what the
   parent's `@throws` allows (or subtypes), per ADR-0009.
4. **Variance** enforced by the PHP engine on native types is not re-checked;
   PHPDoc narrowing is (for example the parent returns `int<0, max>` and the
   implementation returns `int`).

## 3. Reporting and invariants
A report names the implementation, the parent contract, and the minimal
counterexample.

`#[ClassInvariant]` is inherited by all implementations in scope; until stateful
testing exists it is checked after construction and after each verified call
only. History constraints (subtypes must not break parent-guaranteed state
transitions) and arbitrary call orders are deferred to stateful testing.
