<?php

declare(strict_types=1);

namespace Pront\Engine;

/**
 * What one run verified (`Engine::run()`); the pre-run errors stay in the `DiscoveryResult`.
 */
final readonly class RunReport
{
    /**
     * @param list<TargetReport> $targets in discovery order
     */
    public function __construct(
        public int $runSeed,
        public array $targets,
    ) {
    }
}
