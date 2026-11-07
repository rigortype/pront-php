<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * The exception classes a method declares with `@throws` (ADR-0009 section 5). An empty list is
 * `@throws void`: nothing is thrown.
 */
final readonly class ThrowsDeclaration
{
    /**
     * @param list<class-string<\Throwable>> $classes
     */
    public function __construct(
        public array $classes,
    ) {
    }
}
