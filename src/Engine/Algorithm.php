<?php

declare(strict_types=1);

namespace Pront\Engine;

/**
 * The algorithm identifier (ADR-0004 item 4, ADR-0011 section 1): it names everything that decides
 * which input a (target seed, k) or a recorded buffer produces, so a replay token is only honoured
 * by the same identifier. Version `pront-1` covers:
 *
 * - the stream derivation of `Prng::forTrial()` (SHA-256 of `"pront.trial\0"`, the 64-bit
 *   little-endian target seed and the 64-bit little-endian `k`, as Xoshiro256** state) and the
 *   target seed derivation of `Prng::targetSeed()`; `k` counts fresh trials of one target from 1;
 * - Pront's own range mapping in `Prng` (masked rejection sampling, `chance()`, `weighted()`);
 * - the slot encodings and orderings of the choice buffer (`IntOrder`, `FloatCodec`, `Characters`,
 *   the length and index draws) and the edge tables (`EdgeValues`, the float edge table);
 * - the draw order of the generators, and of one trial: the receiver first (an instance method),
 *   then the parameters in declaration order (`Engine::generateInput()`).
 *
 * Any change to one of these is an identifier bump.
 */
final class Algorithm
{
    public const string ID = 'pront-1';
}
