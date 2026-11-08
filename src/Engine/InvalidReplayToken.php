<?php

declare(strict_types=1);

namespace Pront\Engine;

/**
 * A `--replay` argument that cannot be replayed: not a token, damaged, from another Pront version or
 * algorithm, or naming a target that is not a verified target of this run.
 */
final class InvalidReplayToken extends \InvalidArgumentException
{
}
