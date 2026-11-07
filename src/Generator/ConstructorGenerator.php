<?php

declare(strict_types=1);

namespace Pront\Generator;

use Pront\ChoiceBuffer\ChoiceBuffer;

/**
 * An instance built through its public constructor from generated arguments (the Stage 1 subset of
 * ADR-0010 section 2: the receiver of an instance method). An `\Exception` thrown by the
 * constructor rejects the candidate (ADR-0014 section 3: a discard); an `\Error` propagates.
 */
final readonly class ConstructorGenerator implements Generator
{
    /**
     * @param class-string $class
     */
    public function __construct(
        private string $class,
        private ArgumentsGenerator $arguments,
    ) {
    }

    public function generate(ChoiceBuffer $buffer): object
    {
        $arguments = $this->arguments->generate($buffer);
        try {
            return new ($this->class)(...$arguments);
        } catch (\Exception $exception) {
            throw new Rejected(
                sprintf('%s::__construct() threw %s: %s', $this->class, $exception::class, $exception->getMessage()),
                previous: $exception,
            );
        }
    }
}
