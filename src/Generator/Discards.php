<?php

declare(strict_types=1);

namespace Pront\Generator;

/**
 * Discard accounting for one target (ADR-0010 section 5): every rejected candidate is counted, and
 * exceeding the threshold (`Defaults::DISCARD_FACTOR` times the iteration count, ADR-0007 section 2)
 * is a generator-configuration error, never a silent stop.
 */
final class Discards
{
    public readonly int $threshold;

    private int $count = 0;

    /** @var array<string, int> reason => count */
    private array $reasons = [];

    public function __construct(int $iterations)
    {
        if ($iterations < 1) {
            throw new \InvalidArgumentException('The iteration count must be positive');
        }
        $this->threshold = Defaults::DISCARD_FACTOR * $iterations;
    }

    /**
     * Counts one discard.
     *
     * @throws TooManyDiscards when the count exceeds the threshold
     */
    public function record(Rejected $rejection): void
    {
        $this->count++;
        $reason = $rejection->getMessage();
        $this->reasons[$reason] = ($this->reasons[$reason] ?? 0) + 1;
        if ($this->count > $this->threshold) {
            arsort($this->reasons);
            throw new TooManyDiscards(sprintf(
                '%d candidates were discarded, more than the threshold of %d; most frequent reason (%d times): %s',
                $this->count,
                $this->threshold,
                reset($this->reasons),
                array_key_first($this->reasons),
            ));
        }
    }

    public function count(): int
    {
        return $this->count;
    }
}
