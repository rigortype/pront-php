<?php

declare(strict_types=1);

namespace Pront\Generator;

use Pront\ChoiceBuffer\ChoiceBuffer;

/**
 * `string` and `non-empty-string`: the selector, then either an edge string or a uniform string of
 * a drawn length in characters (code points), each character drawn and encoded as UTF-8, so the
 * uniform draw is always valid UTF-8 (ADR-0007 section 1). With no stated maximum the length is at
 * most `ChoiceBuffer::DEFAULT_MAX_LENGTH` characters; the edge strings are not bound by that
 * default, which only shapes the uniform draw.
 */
final readonly class StringGenerator implements Generator
{
    /** @var list<string> */
    private array $edges;

    private int $minLength;

    /**
     * @param bool $arrayKey the string is an array key: the edge `"0"` is omitted (ADR-0007 section 1)
     */
    public function __construct(
        bool $nonEmpty,
        bool $arrayKey,
        private float $edgeRatio,
    ) {
        $this->edges = EdgeValues::strings($nonEmpty, $arrayKey);
        $this->minLength = $nonEmpty ? 1 : 0;
    }

    public function generate(ChoiceBuffer $buffer): string
    {
        $edge = $buffer->drawSelector(count($this->edges), $this->edgeRatio);
        if ($edge !== null) {
            return $this->edges[$edge];
        }
        $length = $buffer->drawLength($this->minLength);
        $string = '';
        for ($i = 0; $i < $length; $i++) {
            $string .= Utf8::encode($buffer->drawCharacter());
        }

        return $string;
    }
}
