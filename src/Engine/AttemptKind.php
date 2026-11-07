<?php

declare(strict_types=1);

namespace Pront\Engine;

/**
 * What one attempt is (ADR-0004 item 5): a fresh trial regenerated from (seed, k), or a run of a
 * recorded buffer (a shrink candidate, a flaky re-run of the counterexample, or a `--replay`).
 */
enum AttemptKind: string
{
    case Fresh = 'fresh';
    case Shrink = 'shrink';
    case Flaky = 'flaky';
    case Replay = 'replay';
}
