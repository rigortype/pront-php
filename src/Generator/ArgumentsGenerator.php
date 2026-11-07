<?php

declare(strict_types=1);

namespace Pront\Generator;

use Pront\ChoiceBuffer\ChoiceBuffer;

/**
 * The arguments of one call: each parameter in declaration order, keyed by name (without `$`), so
 * the result can be spread as named arguments.
 */
final readonly class ArgumentsGenerator implements Generator
{
    /**
     * @param array<string, Generator> $parameters
     */
    public function __construct(
        private array $parameters,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(ChoiceBuffer $buffer): array
    {
        $arguments = [];
        foreach ($this->parameters as $name => $parameter) {
            $arguments[$name] = $parameter->generate($buffer);
        }

        return $arguments;
    }
}
