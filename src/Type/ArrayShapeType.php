<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * A sealed array shape with required keys only, `array{a: int, 0: string}` (ADR-0003 item 3:
 * optional keys, unsealed shapes and `list{...}` are pre-run errors in Stage 1). Keys are
 * normalized as PHP does: a canonical decimal integer string becomes an int key.
 */
final readonly class ArrayShapeType implements Type
{
    /**
     * @param array<int|string, Type> $items key => value type, in declaration order
     */
    public function __construct(
        public array $items,
    ) {
    }

    public function describe(): string
    {
        $parts = [];
        foreach ($this->items as $key => $type) {
            $parts[] = self::describeKey($key) . ': ' . $type->describe();
        }

        return 'array{' . implode(', ', $parts) . '}';
    }

    private static function describeKey(int|string $key): string
    {
        if (is_int($key) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $key) === 1) {
            return (string) $key;
        }

        return "'" . addcslashes($key, "'\\") . "'";
    }
}
