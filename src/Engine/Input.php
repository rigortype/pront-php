<?php

declare(strict_types=1);

namespace Pront\Engine;

/**
 * The generated input of one attempt: the receiver of an instance method (null for a static
 * method) and the arguments keyed by parameter name, ready to spread as named arguments.
 */
final readonly class Input
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public ?object $receiver,
        public array $arguments,
    ) {
    }
}
