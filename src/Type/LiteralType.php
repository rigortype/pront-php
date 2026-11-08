<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * A literal (constant) type: `1`, `-5`, `1.5`, `'foo'`, `true`, `false`.
 */
final readonly class LiteralType implements Type
{
    public function __construct(
        public int|float|string|bool $value,
    ) {
    }

    public function describe(): string
    {
        return match (true) {
            is_bool($this->value) => $this->value ? 'true' : 'false',
            is_string($this->value) => "'" . addcslashes($this->value, "'\\") . "'",
            is_float($this->value) => var_export($this->value, true),
            default => (string) $this->value,
        };
    }
}
