<?php

declare(strict_types=1);

namespace Pront\Oracle;

use Pront\Discovery\BindingKind;
use Pront\Discovery\Condition;
use Pront\Discovery\Target;
use Pront\Engine\Engine;
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
 *    arguments as named arguments. A condition's parameter binds the generated argument, the value
 *    before the call.
 * 2. A throwable is checked against `@throws` (ADR-0009 section 5): with a `@throws` tag, any
 *    throwable that is not an instance of a declared class fails (`@throws void` declares none);
 *    without one, an `Error` fails and an `Exception` passes unchecked. A declared throwable passes.
 *    Neither the return type nor `Ensures` can run without a return value: an unchecked `Exception`
 *    is counted per target (`uncheckedExceptions()`) so the report can list it, never a silent pass.
 * 3. A returned value is checked against the declared return type (`ReturnTypeCheck`). A fragment
 *    the stage cannot check answers "maybe" and passes; it is listed by `uncheckedFragments()`.
 * 4. Each `#[Ensures]`, in source order, is called with its bound values: it must return true.
 *    False, a non-bool (also when the condition's own return type rejects it) and a throwable fail
 *    with distinct kinds; a `TypeError` binding a value to a condition parameter makes the trial
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

    /**
     * The engine's methods that call user code (a throwable created while generating the input);
     * the same list as `Engine::outerFrames()`, which is private to the engine.
     */
    private const array ENGINE_CALLERS = ['generateInput', 'runProperty', 'releaseInput'];

    /** @var array<string, int<1, max>> target name => attempts that ended in an unchecked `Exception` */
    private array $uncheckedExceptions = [];

    public function __invoke(Target $target, Input $input): Outcome
    {
        $returned = null;
        $thrown = null;
        try {
            $returned = $this->callTarget($target, $input);
        } catch (\Throwable $thrown) {
        }
        if ($thrown !== null) {
            if ($target->signature->throws === null && $thrown instanceof \Exception) {
                $this->uncheckedExceptions[$target->name()] = ($this->uncheckedExceptions[$target->name()] ?? 0) + 1;
            }
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
     * Per target, how many attempts this oracle ran ended in an `Exception` that a method without
     * `@throws` may throw (ADR-0009 section 5): those attempts passed without a return-type or
     * `Ensures` check, so a report lists them. Every attempt counts (fresh trials, shrink candidates,
     * flaky re-runs and replays alike).
     *
     * @return array<string, int<1, max>>
     */
    public function uncheckedExceptions(): array
    {
        return $this->uncheckedExceptions;
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
            $outcome = self::conditionThrew($condition, $thrown);
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
     * A `TypeError` the condition raised at its own boundary: the innermost frame is the condition
     * called from `callCondition()`, and the message names that same function (an internal call the
     * engine compiles without a frame of its own, such as `\strlen()`, names itself instead).
     * Binding a value to a parameter makes the trial unverifiable (ADR-0009 section 4); the
     * condition's own return type rejecting a non-bool result is a non-bool result, and rejecting
     * `false` (a `: true` condition) is a false result. Anything else, including a `TypeError` from
     * code the condition calls, is a throw.
     */
    private static function conditionThrew(Condition $condition, \Throwable $thrown): Outcome
    {
        $trace = $thrown->getTrace();
        $function = ($trace[0]['class'] ?? '') . ($trace[0]['type'] ?? '') . ($trace[0]['function'] ?? '');
        $boundary = $thrown instanceof \TypeError
            && ($trace[1]['class'] ?? null) === self::class && ($trace[1]['function'] ?? null) === 'callCondition'
            && strstr($thrown->getMessage(), '(): ', true) === $function;
        if ($boundary && preg_match('/\(\): Argument #\d+ \(\$\w+\) must be of type /', $thrown->getMessage()) === 1) {
            return FailureKind::Unverifiable->outcome($condition->subject . ': ' . self::signature($thrown));
        }
        if ($boundary && preg_match('/\(\): Return value must be of type .+, (\S+) returned$/', $thrown->getMessage(), $match) === 1
            && !in_array($match[1], ['true', 'bool'], true)) {
            return $match[1] === 'false'
                ? FailureKind::EnsuresFalse->outcome($condition->subject)
                : FailureKind::EnsuresNotBool->outcome($condition->subject . ' returned ' . $match[1]);
        }

        return FailureKind::EnsuresThrew->outcome($condition->subject . ': ' . self::signature($thrown));
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
     * call into user code: they differ between a fresh trial and a shrink candidate. A throwable
     * created before the call (while the engine generated the input) has no such frame; the frames
     * above the engine's call into user code are dropped instead.
     */
    private static function signature(\Throwable $thrown): string
    {
        $trace = $thrown->getTrace();
        $outer = 0;
        foreach ($trace as $index => $frame) {
            $class = $frame['class'] ?? null;
            if (($class === self::class && in_array($frame['function'], self::CALLERS, true))
                || ($class === Engine::class && in_array($frame['function'], self::ENGINE_CALLERS, true))) {
                $outer = count($trace) - $index;
                break;
            }
        }

        return (string) Outcome::fromThrowable($thrown, $outer)->signature;
    }
}
