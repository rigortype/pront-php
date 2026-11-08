# ADR-0007: Edge values and provisional defaults

- Status: Accepted
- Date: 2026-11-07
- Amended: 2026-11-08 (Stage 1 findings: concrete strings, edge strings and the default maximum
  length, the float edge weight, edge-list order; `string` keys are never drawn in an integer form)
- Refines: ADR-0010 section 4, ADR-0011 section 1

## Context

ADR-0010 section 4 mixes boundary values with uniform random values by default, owned by an ADR, with
provisional defaults. This ADR fixes the initial list and defaults.

Facts verified on PHP 8.5.12: supplementary-plane characters take 4 bytes in UTF-8 (`U+20BB7` and
`U+29E3D`), a family emoji is 7 code points and 25 bytes, a flag is 2 code points (regional
indicators), `か` + `U+3099` differs byte-wise from `が`, and `"\xff"` is not valid UTF-8. The
"surrogate pair" term belongs to UTF-16; in UTF-8 this ADR speaks of the BMP and non-BMP planes.

## Decision

### 1. Edge values (in-range only; a range's own endpoints are always edge values)
- **int**: `0`, `1`, `-1`, `2`, `PHP_INT_MAX`, `PHP_INT_MIN`, `2^31-1`, `2^31`, `-2^31`, `2^32-1`,
  `2^32`.
- **float** (the only float edge table; ADR-0002 `kind` 2 indexes it in this order): `-0.0`, `NAN`,
  `INF`, `-INF`, `PHP_FLOAT_EPSILON`, `PHP_FLOAT_MIN`, `PHP_FLOAT_MAX`. The values `0.0`, `1.0` and
  `-1.0` are small integer values (ADR-0002 `kind` 0) and stay edge candidates by that route.
- **bool**: both. **array / list**: empty, one element, the length range's lower and upper bounds.
- **string** (valid UTF-8 by default):
  - basics: `""`, `" "`, `"0"`, `"00"`, `"1.5"`, `"\n"`, `"\0"`, `str_repeat('a', 1000)` (1000 bytes);
  - Japanese kanji (`日本語`) and variants (`髙`, `﨑`); simplified (`汉字`, `简体`) and
    traditional (`漢字`, `繁體`) characters;
  - supplementary-plane kanji (CJK Extension B and later: `𠮷` U+20BB7, `𩸽` U+29E3D, `𠀋` U+2000B) and an
    ideographic variation sequence (`葛` + `U+E0100`);
  - multi-code-point emoji: a family (`👨‍👩‍👧‍👦`, ZWJ sequence), `🏳️‍🌈`, a flag (`🇯🇵`), a skin-tone
    modified `👍🏽`, a keycap `1️⃣`, a variation-selector heart `❤️`;
  - normalization and combining: `e` + `U+0301`, `が` versus `か` + `U+3099`, full-width and
    half-width katakana (`カタカナ`, `ｶﾀｶﾅ`), Hangul jamo (`각` as U+1100 U+1161 U+11A8),
    right-to-left Arabic text (`مرحبا`), a lone ZWJ, a BOM.
- **Invalid UTF-8** (`"\xff"`, and the truncated sequence `"\xE6\x97"`, the first two bytes of `日`)
  is in the edge mix, because a PHP `string` is a byte string, but never in the uniform draw, which
  yields valid UTF-8.
- **Order.** Each type's edge list (the selector order of ADR-0002, so the first is the simplest edge)
  is the table order above, then the range's endpoints not already listed; for strings the
  1000-byte string follows the other basics and invalid UTF-8 comes last.
- **Lengths.** A string length drawn from the type counts *characters* (code points) when the string is
  generated; a constraint that the type states in bytes (for example a `strlen` condition) is checked
  against bytes afterwards, and violating candidates are rejected (ADR-0010). The minimum length of
  `non-empty-string` is 1. Edge strings are candidates only when they satisfy the type; the default
  maximum length (section 2) shapes only the uniform draw and does not bound them, so
  `str_repeat('a', 1000)` is an edge of `string`.
- **Array keys.** For `array<string, V>` keys, the edge `"0"` is omitted (PHP turns it into the integer
  key `0`), and a key is never drawn in a form PHP converts to an integer key (ADR-0014 section 3), so
  keys cause no discard. This replaces the earlier rule that rejected such a key and counted it toward
  the discard threshold, under which rejections compounded with nesting (`list<list<array<string,
  int>>>` measured about 305 discards per 100 accepted values). Stage 1 as merged still rejects; the
  key draw follows in #35. For `array<int|string, V>` a converted key is kept as the int key the type
  admits. A repeated key overwrites, so an array may be shorter than its drawn length.

### 2. Provisional defaults (review point: after Stage 1 runs on real code)
- `iterations`: 100. Edge-value ratio: 10%; for floats it is the probability of `kind` 2 (ADR-0002
  section 3).
- Discard threshold: 10 times `iterations`. Flaky re-run count N: 5.
- Shrink budget, owned here: at most 1000 candidate evaluations (`maxShrinkSteps`, an internal
  constant, not a user option in Stage 1) and 60 seconds of wall time as a safety net, checked
  between candidate evaluations (it cannot interrupt user code, ADR-0004).
- Character classes, their weights and the default maximum length (16, or the type's minimum when that
  is larger) are set in ADR-0002 section 3 and reviewed with these defaults.
- A per-trial timeout is not a Stage 1 default (ADR-0004).

## Alternatives rejected

| Candidate | Reason |
| --- | --- |
| Generate invalid UTF-8 by default | Most code under test expects text; the byte-string case belongs to explicit edge values. |
| Count string length in code points or graphemes | PHP's `strlen` and PHPStan's string types are byte-based. |

## Consequences

- The string list is large on purpose: text handling is a common source of bugs in multi-byte
  languages.
- The list is a fixed table; changing it changes which inputs a given seed produces, so it is an
  algorithm-identifier bump (ADR-0011).
- Revisit the ratio and the thresholds with measurements from real projects.
