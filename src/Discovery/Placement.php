<?php

declare(strict_types=1);

namespace Pront\Discovery;

/**
 * Pront Attributes found somewhere other than on a method of a named class-like: a function, a
 * class, a property, a parameter, a constant, a closure or an anonymous class. None of them is a
 * Stage 1 target. `$reflect` gives the reflector once the file is loaded, so `newInstance()`
 * reports the misplacement in PHP's own words (ADR-0006); it is null where no reflector can be
 * reached (closures, anonymous classes), and `$unreachable` says why.
 */
final readonly class Placement
{
    /**
     * @param list<AttributeUse> $attributes
     * @param ?\Closure(): (\ReflectionClass<object>|\ReflectionFunction|\ReflectionParameter|\ReflectionProperty|\ReflectionClassConstant|\ReflectionConstant) $reflect
     */
    public function __construct(
        public string $subject,
        public string $file,
        public array $attributes,
        public ?\Closure $reflect,
        public ?string $unreachable = null,
    ) {
    }
}
