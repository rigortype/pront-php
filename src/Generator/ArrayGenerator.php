<?php

declare(strict_types=1);

namespace Pront\Generator;

use Pront\ChoiceBuffer\ChoiceBuffer;

/**
 * `array<K, V>`: a length as for a list, then that many key/value pairs, each key generated before
 * its value. A key that repeats an earlier one overwrites it, as an assignment does, so the array
 * may be shorter than the drawn length.
 *
 * A generated `string` key that PHP would turn into an int key (a canonical decimal integer string
 * such as `'123'`) is rejected unless the key type also admits that int (ADR-0007 section 1,
 * ADR-0014 section 3): the trial is a discard.
 */
final readonly class ArrayGenerator implements Generator
{
    private Lengths $lengths;

    /**
     * @param \Closure(int): bool $keyAdmitsInt whether the key type admits an int key
     */
    public function __construct(
        private Generator $key,
        private \Closure $keyAdmitsInt,
        private Generator $value,
        float $edgeRatio,
    ) {
        $this->lengths = new Lengths(0, $edgeRatio);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function generate(ChoiceBuffer $buffer): array
    {
        $length = $this->lengths->draw($buffer);
        $array = [];
        for ($i = 0; $i < $length; $i++) {
            $key = $this->key->generate($buffer);
            if (!is_int($key) && !is_string($key)) {
                throw new \LogicException('An array key generator produced ' . get_debug_type($key));
            }
            $stored = array_key_first([$key => true]);
            if ($stored !== $key && !($this->keyAdmitsInt)($stored)) {
                throw new Rejected(sprintf('string key %s would become the int key %d', var_export($key, true), $stored));
            }
            $array[$key] = $this->value->generate($buffer);
        }

        return $array;
    }
}
