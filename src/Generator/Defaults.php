<?php

declare(strict_types=1);

namespace Pront\Generator;

use Pront\ChoiceBuffer\FloatCodec;

/**
 * The provisional generation defaults of ADR-0007 section 2 (review point: after Stage 1 runs on
 * real code). The default maximum length (16) is `ChoiceBuffer::DEFAULT_MAX_LENGTH`.
 */
final class Defaults
{
    public const int ITERATIONS = 100;

    /** The edge-value ratio: the probability that a value is drawn from its edge list. */
    public const float EDGE_RATIO = FloatCodec::DEFAULT_EDGE_RATIO;

    /** The discard threshold is this factor times the iteration count. */
    public const int DISCARD_FACTOR = 10;
}
