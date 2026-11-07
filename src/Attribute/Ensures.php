<?php

declare(strict_types=1);

namespace Pront\Attribute;

/**
 * A postcondition of the method that carries it (ADR-0009 section 4): a condition that must return
 * true after a call. Its parameters bind by name to the method's parameters and to the reserved
 * names `$return` and `$self` (ADR-0014 section 3, Stage 1).
 *
 * The condition is a `static function` closure, a first-class callable (`Checks::atLeast(...)`) or
 * a static method reference (`[Checks::class, 'atLeast']`); the last is turned into a Closure here,
 * so a reference that is not callable fails in `newInstance()` and becomes a pre-run error.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class Ensures
{
    public \Closure $condition;

    /**
     * @param \Closure|array{class-string, string} $condition
     */
    public function __construct(\Closure|array $condition)
    {
        if ($condition instanceof \Closure) {
            $this->condition = $condition;

            return;
        }
        if (!array_is_list($condition) || count($condition) !== 2
            || !is_string($condition[0]) || !is_string($condition[1])) {
            throw new \InvalidArgumentException('a static method reference must be [Class::class, \'method\']');
        }
        [$class, $method] = $condition;
        if (!method_exists($class, $method)) {
            throw new \InvalidArgumentException("{$class}::{$method}() does not exist");
        }
        $reflection = new \ReflectionMethod($class, $method);
        if (!$reflection->isStatic() || !$reflection->isPublic()) {
            throw new \InvalidArgumentException("{$class}::{$method}() is not a public static method");
        }
        $this->condition = $reflection->getClosure();
    }
}
