<?php

declare(strict_types=1);

namespace Pront\Oracle;

use Pront\Discovery\BindingKind;
use Pront\Discovery\Condition;
use Pront\Discovery\Target;
use Pront\Engine\Input;
use Pront\Shrinker\Outcome;
use Pront\Type\Certainty;
use Pront\Type\ThrowsDeclaration;

/**
 * The Stage 1 oracles (ADR-0014 section 3) as the engine's property: an instance is a
 * `callable(Target, Input): Outcome` (`new Engine($journal, new Oracle())`). One call runs the
 * target once with the input and decides the trial:
 *
 * 1. The method is called on the input's receiver (none for a static method) with the input's
 *    arguments as named arguments.
 * 2. A throwable is checked against `@throws` (ADR-0009 section 5): with a `@throws` tag, any
 *    throwable that is not an instance of a declared class fails (`@throws void` declares none);
 *    without one, an `Error` fails and an `Exception` passes unchecked. A declared throwable passes.
 * 3. A returned value is checked against the declared return type (`ReturnTypeCheck`). A fragment
 *    the stage cannot check answers "maybe" and passes; it is listed by `uncheckedFragments()`.
 * 4. Each `#[Ensures]`, in source order, is called with its bound values: it must return true.
 *    False, a non-bool and a throwable fail with distinct kinds; a `TypeError` makes the trial
 *    unverifiable (ADR-0009 section 4). The first condition that does not hold decides.
 *
 * Same failure: the signature (see `Outcome`) names the failure kind and the target or condition,
 * never the input. A return-type failure adds the returned value's debug type and the declared
 * type; a throwable adds `Outcome::fromThrowable()`'s signature with the frames above this class's
 * call into user code dropped, so a fresh trial and a shrink candidate that fail the same way have
 * the same signature.
 *
 * Code that ends the process or is declared `never` is undefined behaviour (ADR-0004 item 7).
 */
final class Oracle
{
    /** The methods of this class that call user code; frames above them are not in a signature. */
    private const array CALLERS = ['callTarget', 'callCondition'];

    public function __invoke(Target $target, Input $input): Outcome
    {
        $returned = null;
        $thrown = null;
        try {
            $returned = $this->callTarget($target, $input);
        } catch (\Throwable $thrown) {
        }
        if ($thrown !== null) {
            $outcome = self::allows($target->signature->throws, $thrown)
                ? Outcome::passed()
                : FailureKind::UndeclaredThrowable->outcome($target->name() . ': ' . self::signature($thrown));
            $thrown = null;

            return $outcome;
        }

        $declared = $target->signature->return
            ?? throw new \LogicException("{$target->name()} has no resolved return type");
        $static = $input->receiver !== null ? $input->receiver::class : $target->method->class;
        if (ReturnTypeCheck::check($declared, $returned, $static) === Certainty::No) {
            return FailureKind::ReturnType->outcome(sprintf(
                '%s returned %s, declared %s',
                $target->name(),
                get_debug_type($returned),
                $declared->describe(),
            ));
        }

        foreach ($target->conditions as $condition) {
            $outcome = $this->ensures($condition, $input, $returned);
            if ($outcome !== null) {
                return $outcome;
            }
        }

        return Outcome::passed();
    }

    /**
     * The return-type fragments of `$targets` that are reported as "unchecked" (ADR-0009 section 2),
     * in target order.
     *
     * @param iterable<Target> $targets
     * @return list<UncheckedFragment>
     */
    public static function uncheckedFragments(iterable $targets): array
    {
        $fragments = [];
        foreach ($targets as $target) {
            foreach ($target->signature->uncheckedReturnFragments() as $fragment) {
                $fragments[] = new UncheckedFragment($target->name(), $fragment);
            }
        }

        return $fragments;
    }

    /**
     * Whether `@throws` allows `$thrown` (ADR-0009 section 5). Without a `@throws` tag only an
     * `Exception` is allowed: methods without `@throws` are not checked for `Exception`, but an
     * `Error` is a failure unless declared.
     */
    public static function allows(?ThrowsDeclaration $throws, \Throwable $thrown): bool
    {
        if ($throws === null) {
            return $thrown instanceof \Exception;
        }
        foreach ($throws->classes as $class) {
            if ($thrown instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function callTarget(Target $target, Input $input): mixed
    {
        $method = $target->method->getClosure($target->method->isStatic() ? null : $input->receiver);

        return $method(...$input->arguments);
    }

    /**
     * The failing outcome of one condition, or null when it held.
     */
    private function ensures(Condition $condition, Input $input, mixed $returned): ?Outcome
    {
        $values = [];
        foreach ($condition->bindings as $binding) {
            $values[] = match ($binding->kind) {
                BindingKind::Return => $returned,
                BindingKind::Receiver => $input->receiver,
                BindingKind::Parameter => array_key_exists($binding->parameter ?? '', $input->arguments)
                    ? $input->arguments[$binding->parameter]
                    : throw new \LogicException("{$condition->subject} binds \${$binding->parameter}, which the input lacks"),
            };
        }

        $thrown = null;
        $result = null;
        try {
            $result = $this->callCondition($condition, $values);
        } catch (\Throwable $thrown) {
        }
        if ($thrown !== null) {
            $kind = $thrown instanceof \TypeError ? FailureKind::Unverifiable : FailureKind::EnsuresThrew;
            $outcome = $kind->outcome($condition->subject . ': ' . self::signature($thrown));
            $thrown = null;

            return $outcome;
        }

        return match (true) {
            $result === true => null,
            $result === false => FailureKind::EnsuresFalse->outcome($condition->subject),
            default => FailureKind::EnsuresNotBool->outcome($condition->subject . ' returned ' . get_debug_type($result)),
        };
    }

    /**
     * @param list<mixed> $values
     */
    private function callCondition(Condition $condition, array $values): mixed
    {
        return ($condition->closure)(...$values);
    }

    /**
     * `Outcome::fromThrowable()`'s signature of `$thrown`, without the frames above this class's
     * call into user code: they differ between a fresh trial and a shrink candidate.
     */
    private static function signature(\Throwable $thrown): string
    {
        $trace = $thrown->getTrace();
        $outer = 0;
        foreach ($trace as $index => $frame) {
            if (($frame['class'] ?? null) === self::class && in_array($frame['function'], self::CALLERS, true)) {
                $outer = count($trace) - $index;
                break;
            }
        }

        return (string) Outcome::fromThrowable($thrown, $outer)->signature;
    }
}
