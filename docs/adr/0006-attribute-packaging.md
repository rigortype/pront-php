# ADR-0006: Attribute packaging

- Status: Accepted
- Date: 2026-11-07
- Refines: ADR-0014 section 1

## Context

ADR-0014 intends Pront for `require-dev` and defers whether Attribute classes should be a separate
package, so that production code carrying Attributes does not depend on the tool.

Facts verified on PHP 8.5.12: when an Attribute class does not exist, `ReflectionFunction::
getAttributes()` still returns the attribute with its name and arguments; only `newInstance()` fails
(`Attribute class "Missing\Verify" not found`). Code that never instantiates attributes is
unaffected by their absence. Separately, a closure inside an Attribute makes the file require
PHP 8.5 at compile time regardless of packaging (ADR-0009 section 4).

## Decision

1. One package, `rigortype/pront`, contains the engine, the CLI and the Attribute classes under
   `Pront\Attribute\`.
2. Production code that carries Pront Attributes keeps working without Pront installed, as long as
   nothing instantiates them. Pront itself reads them with `ReflectionAttribute::newInstance()`, so
   PHP's own validation (unknown target, repeated non-repeatable attribute, constructor argument
   errors) applies and its failures become pre-run errors; Pront does not re-implement that checking
   from names and arguments.
3. Splitting the Attribute classes into their own package is re-examined before 1.0. Until then
   Attribute signatures may change incompatibly, recorded in the CHANGELOG (ADR-0014 section 2).

## Alternatives rejected

| Candidate | Reason |
| --- | --- |
| Separate attributes package now | Little benefit: absence is harmless without `newInstance()`, and the 8.5 requirement of the closure form is unaffected. It adds a second release train before the API settles. |

## Consequences

- Users add one `require-dev` entry.
- Static analysis of production code that carries Pront Attributes may report an unknown class when
  Pront is absent; that is a tooling matter, not a runtime one.
- Revisit before 1.0, or earlier if users report production-side problems.
