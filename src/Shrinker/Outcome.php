<?php

declare(strict_types=1);

namespace Pront\Shrinker;

/**
 * What one shrink candidate's replay produced: the property passed, the input was discarded, or
 * the property failed with a failure signature.
 *
 * Same failure (ADR-0002 section 4 accepts a candidate only if it "still fails"): two failures are
 * the same when their signatures are equal strings, nothing looser. `fromThrowable()` is the
 * conservative signature for a thrown failure: the exception class, the file and line that threw
 * it, and the file and line of every frame of its stack trace (so one assertion helper or callee
 * reached from two call sites gives two signatures), never the message or the arguments (they
 * usually embed the input, so they would change with every candidate). A failure whose stack
 * depth depends on the input (recursion) therefore only shrinks among inputs of the same depth. A caller that classifies failures otherwise (a return-type or `Ensures` violation)
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
     * A failure identified by the throwable's class, throw site and call stack (file and line of
     * each frame; a frame without a file, such as an internal call, contributes `-`).
     *
     * `$outerFrames` drops that many outermost frames of the stack trace: a caller that reaches the
     * same code through different call paths (the engine runs a fresh trial and a shrink candidate
     * from different places) drops the frames above its own call into that code, so the same
     * failure keeps the same signature on both paths.
     *
     * @param int<0, max> $outerFrames
     */
    public static function fromThrowable(\Throwable $failure, int $outerFrames = 0): self
    {
        $sites = [sprintf('%s:%d', $failure->getFile(), $failure->getLine())];
        $trace = $failure->getTrace();
        foreach (array_slice($trace, 0, max(0, count($trace) - $outerFrames)) as $frame) {
            $sites[] = isset($frame['file']) ? sprintf('%s:%d', $frame['file'], $frame['line'] ?? 0) : '-';
        }

        return self::failed($failure::class . '@' . implode(' < ', $sites));
    }

    /**
     * Whether this outcome is a failure with the same signature as `$original`.
     */
    public function reproduces(self $original): bool
    {
        return $this->kind === OutcomeKind::Failed && $this->signature === $original->signature;
    }
}
