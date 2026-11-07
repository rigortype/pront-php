<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * `array<K, V>`. In Stage 1 the key is `int`, `string` or `int|string` (ADR-0014 section 3); the
 * native `array` and the PHPDoc `array` are `array<int|string, mixed>`.
 *
 * A `string` key is never a canonical decimal integer string within PHP's int range (`'123'`,
 * `'-5'`): PHP turns such a key into an int (ADR-0014 section 3).
 */
final readonly class ArrayType implements Type
{
    public function __construct(
        public Type $key,
        public Type $value,
    ) {
    }

    public static function native(): self
    {
        return new self(UnionType::of(new IntType(), new StringType()), new MixedType());
    }

    public function describe(): string
    {
        if ($this->value instanceof MixedType && $this->key->describe() === 'int|string') {
            return 'array';
        }

        return 'array<' . $this->key->describe() . ', ' . $this->value->describe() . '>';
    }
}
