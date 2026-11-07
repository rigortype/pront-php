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
 * it, and the file and line of every frame between the throw site and the code that classifies it
 * (so one assertion helper or callee reached from two call sites gives two signatures), never the
 * message or the arguments (they usually embed the input, so they would change with every
 * candidate). The frames the throwable shares with the caller of `fromThrowable()` (the harness
 * that ran the property: a trial loop, or one of the shrinker's passes) are cut, so the same
 * failure has the same signature whichever path ran it. A failure whose stack depth depends on the
 * input (recursion) only shrinks among inputs of the same depth. A caller that classifies failures otherwise (a return-type or `Ensures` violation)
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
     * A failure identified by the throwable's class, throw site and the frames of its stack trace
     * that are not shared with the caller's own stack (file and line of each frame; a frame without
     * a file, such as an internal call, contributes `-`). Call it where the failure is caught.
     */
    public static function fromThrowable(\Throwable $failure): self
    {
        $trace = array_map(self::frame(...), $failure->getTrace());
        // Frame 0 is this call; the rest is the caller's stack, outermost last.
        $own = array_map(self::frame(...), array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 1));
        while ($trace !== [] && $own !== [] && end($trace) === end($own)) {
            array_pop($trace);
            array_pop($own);
        }
        $sites = [sprintf('%s:%d', $failure->getFile(), $failure->getLine())];
        foreach ($trace as $frame) {
            $sites[] = $frame;
        }

        return self::failed($failure::class . '@' . implode(' < ', $sites));
    }

    /**
     * One stack frame as `file:line function`, `-` for the location when it has no file.
     *
     * @param array{file?: string, line?: int, function?: string, class?: string} $frame
     */
    private static function frame(array $frame): string
    {
        $where = isset($frame['file']) ? sprintf('%s:%d', $frame['file'], $frame['line'] ?? 0) : '-';

        return $where . ' ' . ($frame['class'] ?? '') . '::' . ($frame['function'] ?? '');
    }

    /**
     * Whether this outcome is a failure with the same signature as `$original`.
     */
    public function reproduces(self $original): bool
    {
        return $this->kind === OutcomeKind::Failed && $this->signature === $original->signature;
    }
}
