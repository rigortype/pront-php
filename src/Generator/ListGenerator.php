<?php

declare(strict_types=1);

namespace Pront\Generator;

use Pront\ChoiceBuffer\ChoiceBuffer;

/**
 * `list<T>` and `non-empty-list<T>`: the selector picks an edge length (empty, one, the bounds) or
 * a uniform length draw, then each element is generated.
 */
final readonly class ListGenerator implements Generator
{
    private Lengths $lengths;

    public function __construct(
        private Generator $element,
        bool $nonEmpty,
        float $edgeRatio,
    ) {
        $this->lengths = new Lengths($nonEmpty ? 1 : 0, $edgeRatio);
    }

    /**
     * @return list<mixed>
     */
    public function generate(ChoiceBuffer $buffer): array
    {
        $length = $this->lengths->draw($buffer);
        $list = [];
        for ($i = 0; $i < $length; $i++) {
            $list[] = $this->element->generate($buffer);
        }

        return $list;
    }
}
