<?php

declare(strict_types=1);

namespace Pront\Type;

use Pront\PreRun\PreRunError;

/**
 * The resolved types of one function or method. A parameter or return type is null when resolving
 * it produced a pre-run error (listed in `$errors`); a resolved parameter type never contains an
 * unchecked fragment, a resolved return type may.
 */
final readonly class ResolvedSignature
{
    /**
     * @param array<string, ?Type> $parameters parameter name (without `$`) => type, in order
     * @param list<PreRunError> $errors
     */
    public function __construct(
        public string $subject,
        public array $parameters,
        public ?Type $return,
        public ?ThrowsDeclaration $throws,
        public array $errors,
    ) {
    }

    /**
     * The return-type fragments that are reported as "unchecked" (ADR-0009 section 2).
     *
     * @return list<UncheckedType>
     */
    public function uncheckedReturnFragments(): array
    {
        return $this->return === null ? [] : self::unchecked($this->return);
    }

    /**
     * @return list<UncheckedType>
     */
    private static function unchecked(Type $type): array
    {
        $children = match (true) {
            $type instanceof UncheckedType => [],
            $type instanceof UnionType => $type->members,
            $type instanceof ListType => [$type->value],
            $type instanceof ArrayType => [$type->key, $type->value],
            $type instanceof ArrayShapeType => array_values($type->items),
            default => [],
        };

        return $type instanceof UncheckedType
            ? [$type]
            : array_merge([], ...array_map(self::unchecked(...), $children));
    }
}
