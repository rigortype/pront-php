<?php

declare(strict_types=1);

namespace Pront\Shrinker;

/**
 * Internal: unwinds the passes when the shrink budget is spent; never leaves `Shrinker::shrink()`.
 *
 * @internal
 */
final class BudgetSpent extends \RuntimeException
{
    public function __construct(
        public readonly StopReason $reason,
    ) {
        parent::__construct($reason->name);
    }
}
