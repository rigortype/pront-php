<?php

declare(strict_types=1);

namespace Pront\Discovery;

use Pront\PreRun\PreRunError;

/**
 * What discovery hands to the runner: the verified targets, every pre-run error (each aborts only
 * its own target, ADR-0013 section 3), and the classes listed as "unverified until Stage 3"
 * (ADR-0012 section 1).
 */
final readonly class DiscoveryResult
{
    /**
     * @param list<Target> $targets
     * @param list<PreRunError> $errors
     * @param array<string, string> $unverifiedUntilStage3 class => the verified class it extends
     */
    public function __construct(
        public array $targets,
        public array $errors,
        public array $unverifiedUntilStage3,
    ) {
    }
}
