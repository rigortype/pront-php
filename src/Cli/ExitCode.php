<?php

declare(strict_types=1);

namespace Pront\Cli;

/**
 * The exit codes of ADR-0013 section 3.
 */
final class ExitCode
{
    /** Everything requested was verified. */
    public const int SUCCESS = 0;

    /** A counterexample was found (also when other issues occurred in the same run). */
    public const int COUNTEREXAMPLE = 1;

    /** A usage, configuration, pre-run or unverifiable error, nothing to verify, or a `--strict` item. */
    public const int ERROR = 2;
}
