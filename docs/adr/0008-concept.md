# ADR-0008: Project Concept

- Status: Accepted
- Date: 2026-11-07
- Project: Pront (`pront-php`)

This is an Architecture Decision Record. ADRs record every decision of the project: product and
scope ("what we build, for whom, and what we do not build"), architecture and process.

**ADR-0008 is the north star.** It states the concept and the principles only.
Technical requirements, concrete decisions and anything tied to a particular
version of PHP or of a dependency belong to other ADRs, which refine
this document and may change without changing it. If another ADR conflicts
with the principles here, either that ADR is wrong or ADR-0008 must be
revised explicitly.

## 1. Problem

Property-based testing (PBT) in PHP typically has users choose generators
explicitly and write property tests in code separate from the implementation.
Meanwhile many PHP
projects already describe their contracts precisely with native types and
PHPStan-flavoured PHPDoc. Static analysis proves what it can from that
information; nothing samples the actual runtime behaviour against it.

## 2. Concept

Pront synthesizes generators and oracles from the types already present in
PHP code, and lets users declare properties (algebraic laws, contracts,
metamorphic relations) on the implementation itself. Contracts declared on an
interface or parent are verified against every implementation in scope.

Target users: maintainers of typed PHP projects that already run PHPStan.

Inspiration: the idea that useful information should reach tools without
requiring users to write ritual annotations (see mametter's article,
<https://zenn.dev/mametter/articles/3e8580ec034201>, and the rigortype
organization's inference-first direction). Conclusions drawn here, notably
Pillar 5, are our own interpretation.

Positioning: Pront aims to differ from hand-assembled PBT in three ways:
generators derived from types, internal shrinking, and automatic LSP
verification across implementations. These are aims; which of them a given
release delivers is stated by that release (ADR-0014).

## 3. Pillars

1. **Zero boilerplate from types.** Generators and return-type oracles are
   derived from the types and PHPDoc already in the code. No separate test
   file is required.
2. **Internal shrinking.** Counterexamples are minimized by shrinking the
   recorded randomness itself and regenerating, so invariants hold without
   per-type shrinkers.
3. **Metamorphic testing as first-class.** Relations across multiple
   executions (monotonicity, idempotence, batch homomorphism, reference
   comparison) are declared directly.
4. **Dependent generation and higher-order functions (direction).** Arguments
   that depend on each other are generated consistently with their dependencies
   rather than filtered after the fact; function-valued arguments are
   synthesized deterministically. The latter is the intended direction, not an
   early deliverable.
5. **Avoid tautology.** Pront never requires annotations whose only purpose is
   to satisfy the test tool. Test intent (what to verify, how often, which
   properties) is declared; facts already stated by types, language features or
   PHPDoc tags are not restated. The implicit oracle from the declared return
   type is meaningful because static analysis verifies what it can prove while
   Pront samples runtime behaviour. Inferring information where types are
   absent is a possible future direction.
6. **Contract inheritance (LSP verification).** Contracts declared on an
   interface or parent method are verified against every implementation in the
   configured scope. For implementations Pront can construct, no additional
   test code is needed; the others can be made verifiable with user help.
7. **Reproducibility.** A failure found once can be reproduced from what Pront
   reports. This is a contract about Pront's own randomness; it cannot extend
   to code under test that is itself non-deterministic.

## 4. Scope principles

- The declaration lives with the implementation; declaring from the test side
  is a possible future extension, not a non-goal.
- Pront reuses the PHPStan type vocabulary rather than inventing its own, and
  extends it explicitly only where needed.
- Unresolvable situations (a type that cannot be generated, a contradiction
  between declarations) are never silently skipped: they either fail before
  running or are listed in the run's report.
- Pront executes real code. Choosing side-effect-free targets is the user's
  responsibility; opting in is explicit.
- Releases are honest slices: documentation claims only what the shipped
  release does (details in ADR-0014).

## 5. Non-goals

- Not a static analyzer; Pront consumes types and does not replace PHPStan.
- No custom per-type shrinkers.
- No concurrency or async verification.
- No sandboxing of side effects.
- Automatic generator synthesis for code lacking type information is not a
  goal today; it may become a future direction.

## 6. Decision index

The index of all decisions is [`README.md`](README.md); this document keeps no second copy.
