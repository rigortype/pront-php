<?php

declare(strict_types=1);

namespace Pront\PreRun;

/**
 * A situation found before running that prevents a target from being verified as declared
 * (ADR-0008 scope principles: never silently skipped). `$subject` names where it was found, for
 * example `App\Price::add() parameter $amount`.
 */
final readonly class PreRunError
{
    public function __construct(
        public string $subject,
        public string $message,
    ) {
    }

    public function __toString(): string
    {
        return "{$this->subject}: {$this->message}";
    }
}
