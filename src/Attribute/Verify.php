<?php

declare(strict_types=1);

namespace Pront\Attribute;

use Pront\Generator\Defaults;

/**
 * Opts a method in: Pront may execute it with generated input (ADR-0008, Pillar 1). Read with
 * `ReflectionAttribute::newInstance()`, so PHP's own validation (target, repetition, argument types)
 * and the checks below become pre-run errors (ADR-0006).
 *
 * `$seed`, when set, fixes the target's seed instead of deriving it from the run seed (ADR-0004
 * item 4).
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Verify
{
    public function __construct(
        public int $iterations = Defaults::ITERATIONS,
        public ?int $seed = null,
    ) {
        if ($iterations < 1) {
            throw new \InvalidArgumentException("iterations must be at least 1, {$iterations} given");
        }
    }
}
