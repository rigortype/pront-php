<?php

declare(strict_types=1);

namespace Pront\Discovery;

/**
 * One `#[Ensures]` of a verified target, with its parameters already bound: call `$closure` with
 * the values `$bindings` names, in order (positionally).
 */
final readonly class Condition
{
    /**
     * @param list<Binding> $bindings one per closure parameter, in declaration order
     */
    public function __construct(
        public string $subject,
        public \Closure $closure,
        public array $bindings,
    ) {
    }
}
