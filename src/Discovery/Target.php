<?php

declare(strict_types=1);

namespace Pront\Discovery;

use Pront\Attribute\Verify;
use Pront\Generator\ArgumentsGenerator;
use Pront\Generator\Generator;
use Pront\Type\ResolvedSignature;

/**
 * A verified target (GLOSSARY): a concrete method with `#[Verify]` that passed every pre-run check,
 * with what the engine needs to run trials against it. `$receiver` is null for a static method;
 * for an instance method it builds the object (an enum yields one drawn case).
 */
final readonly class Target
{
    /**
     * @param list<Condition> $conditions the method's `#[Ensures]`, in source order
     * @param list<string> $reservedNameConflicts the reserved-name conflicts of ADR-0009 section
     *     4, one message each; counted on the summary line and an error with `--strict`
     */
    public function __construct(
        public \ReflectionMethod $method,
        public Verify $verify,
        public ResolvedSignature $signature,
        public ArgumentsGenerator $arguments,
        public ?Generator $receiver,
        public array $conditions,
        public array $reservedNameConflicts,
    ) {
    }

    /**
     * `Class::method`, the name the target seed is derived from (ADR-0004 item 4).
     */
    public function name(): string
    {
        return $this->method->getDeclaringClass()->getName() . '::' . $this->method->getName();
    }
}
