# ADR-0014: Runtime, Packaging, Versioning and Release Stages

- Status: Accepted
- Date: 2026-11-07
- Amended: 2026-11-08 (Stage 1 findings: `$this` and `object` returns are unchecked; which
  constructor throwables are discards)
- Refines: ADR-0008 (scope principles)

## 1. Runtime and packaging
- Project: Pront (`pront-php`), Composer package `rigortype/pront`,
  namespace `Pront\`.
- Pront requires PHP `>=8.5`. (Consequences for code under test: ADR-0009,
  section 4.)
- The package is intended for `require-dev`; whether Attribute classes should
  be a separate package is for an ADR.

## 2. Versioning
Versions proceed as v0.0.1, v0.0.2, ... and are promoted to v0.1.0 once
things have stabilized. The stability criteria are a product decision, to be
set in a later ADR before v0.1.0 is cut. Before 1.0, Attribute signatures and
behaviour may change incompatibly, with changes recorded in the CHANGELOG.
From 1.0, SemVer applies.

## 3. Release stages
Stage contents are fixed; version numbers are assigned as stages ship (a stage
may span several patch releases).

- **Stage 1 (proof of concept)**
  - Runner: `vendor/bin/pront` with discovery, text output and exit codes 0/1/2
    (ADR-0013); `pront.php` configuration limited to `paths` and `exclude`.
  - Verification: `#[Verify]` with `iterations` (default 100, ADR-0010),
    `edgeRatio` (ADR-0010 section 4; follows in #37) and `seed`; replay tokens
    and `--replay` (ADR-0011); ChoiceBuffer and shrinker.
  - Types: `bool`, `int`, `float`, `string`, `?T`, unions, literals, `list`,
    `non-empty-list<T>`, array shapes, `array<K, V>` (K is `int|string`),
    `int<min, max>`, `positive-int`, `non-negative-int`, `negative-int`,
    `non-empty-string`, backed and unit enums. The `string` key of an
    `array<string, V>` is never a canonical decimal integer string within PHP's
    int range (`'123'`, `'-5'`; unlike `'08'`, `'-0'`, `'1.5'`), because PHP
    would turn it into an int key. Stage 1 checks return types from this list
    plus `void`, `never`, `null`, `mixed`, `self`, `static` and non-generic
    class or interface names; everything else, `$this` and `object` included,
    is reported as "unchecked" (ADR-0009 section 2).
  - Targets: static methods, and instance methods of classes whose public
    constructor takes no parameters or only parameters of the types above (the
    Stage 1 subset of ADR-0010 section 2); an enum method runs on one drawn
    case. A parameter of any other class type, and a private or abstract
    constructor (including a protected one or one of an abstract class), is a
    pre-run error until Stage 2. A constructor exception (an `Exception`,
    `ValueError` or `AssertionError`; any other `Error` is a failure, ADR-0010
    section 2) is a discard, counted toward the discard threshold (ADR-0010
    section 4).
  - Conditions: `#[Ensures]` with `$return`, the method's parameters and
    `$self`. A condition that references `$old` is a pre-run error ("from
    Stage 2", when equality ships).
  - Oracles: the return-type oracle (with "unchecked" reporting) and the
    `@throws` oracle (ADR-0009 section 5): exception class names are resolved
    with the declaring file's namespace and `use` imports, and PHPStan's
    checked/unchecked exception settings are not read.
  - Also in Stage 1: `--strict` (ADR-0013) and the edge-value mix with its ratio
    option (ADR-0010 section 4). Until Stage 3, the pre-run errors of ADR-0012
    section 1 apply to contracts on interface, abstract and overridden methods,
    and every class in scope that extends a verified class is listed as
    "unverified until Stage 3", which `--strict` turns into exit 2.
- **Stage 2**: class-type generation and custom generator registration;
  equality, comparator registration and `$old`; dependency DAG and dynamic
  bounds; algebraic/metamorphic shorthands; JUnit XML and JSON output.
- **Stage 3**: contract inheritance (ADR-0012) and `ClassInvariant` inheritance.
- **Later**: CoArbitrary and `Cover` distribution guards (sketch Phase 4);
  stateful testing, history constraints and the PHPUnit bridge (Phase 5).

The type resolver is an ADR item, but Stage 1's supported types are fixed by
the list above.

## 4. What a release may claim
README, release notes and package descriptions claim only what the shipped
release does. The three differentiators of ADR-0008 (type-derived generators,
internal shrinking, LSP verification) are described as roadmap until their
stage ships: Stage 1 ships internal shrinking and type-derived generators for
the Stage 1 types; LSP verification arrives in Stage 3. Metamorphic
shorthands (ADR-0008 Pillar 3) arrive in Stage 2.
