<?php

declare(strict_types=1);

namespace Pront\Discovery;

/**
 * The value one condition parameter receives; `$parameter` names the method parameter (without
 * `$`) when the kind is Parameter.
 */
final readonly class Binding
{
    public function __construct(
        public BindingKind $kind,
        public ?string $parameter = null,
    ) {
    }
}
