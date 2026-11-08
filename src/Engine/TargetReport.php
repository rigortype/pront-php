<?php

declare(strict_types=1);

namespace Pront\Engine;

/**
 * What verifying one target found (`Engine::verify()`).
 */
final readonly class TargetReport
{
    /**
     * @param int $seed the target seed: `#[Verify(seed:)]`, or derived from the run seed (ADR-0004 item 4)
     * @param int $trials fresh trials run, discarded ones included
     * @param ?string $message why the target stopped, for `TargetStatus::TooManyDiscards`
     */
    public function __construct(
        public string $target,
        public int $seed,
        public TargetStatus $status,
        public int $trials,
        public int $passed,
        public int $discards,
        public ?Counterexample $counterexample = null,
        public ?string $message = null,
    ) {
    }
}
