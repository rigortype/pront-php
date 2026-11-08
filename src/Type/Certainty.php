<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * The answer of a subtype check: `Maybe` when a fragment outside the Stage 1 list makes it
 * undecidable.
 */
enum Certainty
{
    case Yes;
    case No;
    case Maybe;

    /**
     * Every answer must be Yes.
     *
     * @param iterable<self> $answers
     */
    public static function all(iterable $answers): self
    {
        $result = self::Yes;
        foreach ($answers as $answer) {
            if ($answer === self::No) {
                return self::No;
            }
            if ($answer === self::Maybe) {
                $result = self::Maybe;
            }
        }

        return $result;
    }

    /**
     * At least one answer must be Yes.
     *
     * @param iterable<self> $answers
     */
    public static function any(iterable $answers): self
    {
        $result = self::No;
        foreach ($answers as $answer) {
            if ($answer === self::Yes) {
                return self::Yes;
            }
            if ($answer === self::Maybe) {
                $result = self::Maybe;
            }
        }

        return $result;
    }

    public static function of(bool $yes): self
    {
        return $yes ? self::Yes : self::No;
    }
}
