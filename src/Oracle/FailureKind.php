<?php

declare(strict_types=1);

namespace Pront\Oracle;

use Pront\Shrinker\Outcome;
use Pront\Shrinker\OutcomeKind;

/**
 * Which oracle failed one trial. Each failure signature the oracle writes starts with the kind's
 * value and `: `, so a report can tell the kinds apart from the signature alone. A signature from
 * `Outcome::fromThrowable()` (a throwable that escaped the engine's call into user code) starts
 * with a class name and `@`, never with these prefixes.
 */
enum FailureKind: string
{
    /** The returned value is outside the declared return type (ADR-0003 item 5). */
    case ReturnType = 'return type';

    /** A throwable the method's `@throws` does not declare, or an `Error` of a method without `@throws` (ADR-0009 section 5). */
    case UndeclaredThrowable = 'undeclared throwable';

    /** An `#[Ensures]` condition returned false. */
    case EnsuresFalse = 'ensures false';

    /** An `#[Ensures]` condition returned something other than a bool. */
    case EnsuresNotBool = 'ensures not bool';

    /** An `#[Ensures]` condition threw something other than a `TypeError`. */
    case EnsuresThrew = 'ensures threw';

    /**
     * An `#[Ensures]` condition raised a `TypeError`: the trial is *unverifiable* (ADR-0009 section
     * 4), a runtime error with exit code 2 (ADR-0013), not a counterexample.
     */
    case Unverifiable = 'unverifiable';

    /**
     * The kind of a failed outcome the oracle wrote, or null for any other outcome.
     */
    public static function of(Outcome $outcome): ?self
    {
        if ($outcome->kind !== OutcomeKind::Failed || $outcome->signature === null) {
            return null;
        }
        foreach (self::cases() as $kind) {
            if (str_starts_with($outcome->signature, $kind->value . ': ')) {
                return $kind;
            }
        }

        return null;
    }

    public function outcome(string $detail): Outcome
    {
        return Outcome::failed($this->value . ': ' . $detail);
    }
}
