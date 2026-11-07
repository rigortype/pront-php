<?php

declare(strict_types=1);

namespace Pront\Shrinker;

/**
 * What one shrink candidate's replay produced: the property passed, the input was discarded, or
 * the property failed with a failure signature.
 *
 * Same failure (ADR-0002 section 4 accepts a candidate only if it "still fails"): two failures are
 * the same when their signatures are equal strings, nothing looser. `fromThrowable()` is the
 * conservative signature for a thrown failure: the exception class and the file and line that
 * threw it, never the message (a message usually embeds the input, so it would change with every
 * candidate). A caller that classifies failures otherwise (a return-type or `Ensures` violation)
 * passes a signature naming the check that failed, again without the input.
 */
final readonly class Outcome
{
    private function __construct(
        public OutcomeKind $kind,
        public ?string $signature,
    ) {
    }

    public static function passed(): self
    {
        return new self(OutcomeKind::Passed, null);
    }

    public static function discarded(): self
    {
        return new self(OutcomeKind::Discarded, null);
    }

    /**
     * The buffer could not produce an input (`InvalidChoice`); the shrinker records this itself.
     */
    public static function invalid(): self
    {
        return new self(OutcomeKind::Invalid, null);
    }

    public static function failed(string $signature): self
    {
        if ($signature === '') {
            throw new \InvalidArgumentException('A failure signature must not be empty');
        }

        return new self(OutcomeKind::Failed, $signature);
    }

    /**
     * A failure identified by the throwable's class and throw site.
     */
    public static function fromThrowable(\Throwable $failure): self
    {
        return self::failed(sprintf('%s@%s:%d', $failure::class, $failure->getFile(), $failure->getLine()));
    }

    /**
     * Whether this outcome is a failure with the same signature as `$original`.
     */
    public function reproduces(self $original): bool
    {
        return $this->kind === OutcomeKind::Failed && $this->signature === $original->signature;
    }
}
