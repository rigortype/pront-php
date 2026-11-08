<?php

declare(strict_types=1);

namespace Pront\Discovery;

/**
 * A named class, interface, trait or enum found by static analysis (ADR-0013 section 1): what the
 * parent-to-implementation index needs, before anything is loaded.
 */
final readonly class ClassLikeDeclaration
{
    /**
     * @param 'class'|'interface'|'trait'|'enum' $kind
     * @param list<string> $parents extended classes or interfaces, implemented interfaces and used
     *     traits, fully qualified
     * @param array<string, list<AttributeUse>> $prontMethods method name => its Pront Attributes,
     *     for the methods that carry any
     */
    public function __construct(
        public string $name,
        public string $kind,
        public string $file,
        public int $line,
        public array $parents,
        public array $prontMethods,
    ) {
    }
}
