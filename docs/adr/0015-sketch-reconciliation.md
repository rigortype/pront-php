# ADR-0015: Reconciliation with the Original Sketch

- Status: Accepted
- Date: 2026-11-07
- Refines: ADR-0008 to ADR-0014 (`docs/sketch.md` is the original hand-off draft)

The sketch is superseded wherever it disagrees with ADR-0008 to ADR-0014. Known
corrections:

- `random_int` in `ChoiceBuffer` conflicts with `#[Verify(seed: ...)]`;
  replace with the seedable PRNG of ADR-0011.
- `drawInt` computes `$max - $min`, which overflows for full-range ints, and
  "shrink toward 0" should mean toward the simplest value, not toward `$min`.
- `GeneratedCallable` uses `mt_srand`, draws from sub-seeded tapes outside the
  main buffer (contradicting the all-randomness-through-the-buffer rule,
  ADR-0011 section 1), and keys its memo with `serialize` (fails on closures); to
  be redesigned in the CoArbitrary phase.
- Namespace `Pbt\` is a working name; the public namespace is `Pront\`.
- String expressions, `AstDependencyInspector`, `symfony/expression-language`
  are dropped in favour of callables (ADR-0009).
- `#[Gen\IntRange]`, `#[Gen\HostileStrings]`, `#[OnlyThrows]`, `#[NeverThrows]`
  are removed (ADR-0009, ADR-0010).
- `$old` is a deep snapshot, not a shallow `clone` (ADR-0009).
- The `.pbt_replay` file is superseded by replay tokens (ADR-0011, ADR-0013).
