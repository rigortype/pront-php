<?php

declare(strict_types=1);

namespace Pront\Journal;

/**
 * An attempt of an earlier run that began and never ended (ADR-0004 item 8): its run crashed or was
 * killed during it and did not report it. `$fatal` is the message of its `fatal` record when its
 * run's shutdown function recorded one but did not finish reporting it. Found once, at the start of a later run; nothing is
 * re-run automatically.
 */
final readonly class RecoveredAttempt
{
    public function __construct(
        public string $runId,
        public string $kind,
        public string $target,
        public int $k,
        public string $token,
        public ?string $fatal = null,
    ) {
    }

    public function message(): string
    {
        return sprintf(
            "Pront: run %s stopped during a %s attempt of %s (fresh trial %d)%s; nothing was re-run.\nReplay token: %s",
            $this->runId,
            $this->kind,
            $this->target,
            $this->k,
            $this->fatal === null ? '' : ": {$this->fatal}",
            $this->token,
        );
    }
}
