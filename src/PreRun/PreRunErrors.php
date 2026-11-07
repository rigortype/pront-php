<?php

declare(strict_types=1);

namespace Pront\PreRun;

/**
 * Collects every pre-run error instead of stopping at the first, so one run lists them all.
 *
 * @implements \IteratorAggregate<int, PreRunError>
 */
final class PreRunErrors implements \Countable, \IteratorAggregate
{
    /** @var list<PreRunError> */
    private array $errors = [];

    public function add(string $subject, string $message): void
    {
        $this->errors[] = new PreRunError($subject, $message);
    }

    /**
     * @param iterable<PreRunError> $errors
     */
    public function addAll(iterable $errors): void
    {
        foreach ($errors as $error) {
            $this->errors[] = $error;
        }
    }

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return list<PreRunError>
     */
    public function all(): array
    {
        return $this->errors;
    }

    public function count(): int
    {
        return count($this->errors);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->errors);
    }
}
