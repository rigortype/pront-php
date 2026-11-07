<?php

declare(strict_types=1);

namespace Pront\Engine;

use Pront\Shrinker\Outcome;

/**
 * What re-running one replay token produced (`Engine::replay()`). `$note` is the replay-limits note
 * of ADR-0004 item 9 when the replay did not fail.
 */
final readonly class ReplayResult
{
    public function __construct(
        public ReplayToken $token,
        public Outcome $outcome,
        public ?string $note,
    ) {
    }
}
