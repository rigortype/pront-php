<?php

declare(strict_types=1);

namespace Pront\Discovery;

use Pront\Attribute\Ensures;
use Pront\Attribute\Verify;
use Pront\Config\Config;
use Pront\Generator\GeneratorFactory;
use Pront\Generator\UngeneratableType;
use Pront\PreRun\PreRunErrors;
use Pront\Type\Certainty;
use Pront\Type\ClassType;
use Pront\Type\Disjoint;
use Pront\Type\EnumType;
use Pront\Type\FloatType;
use Pront\Type\IntType;
use Pront\Type\LiteralType;
use Pront\Type\NativeTypeConverter;
use Pront\Type\NeverType;
use Pront\Type\PhpDocTypeConverter;
use Pront\Type\ResolvedSignature;
use Pront\Type\Type;
use Pront\Type\TypeResolver;
use Pront\Type\UnionType;
use Pront\Type\VoidType;

/**
 * Target discovery (ADR-0013 section 1) for Stage 1 (ADR-0014 section 3): static analysis of the
 * configured paths picks the candidate files and builds the parent-to-implementation index; only
 * those files are loaded; every Pront Attribute is read with `newInstance()` (ADR-0006); each
 * `#[Verify]` becomes a verified target or pre-run errors. Every pre-run error is collected, none
 * stops the others, and nothing found is skipped silently (ADR-0008 scope principles).
 */
final class Discovery
{
    private const array RESERVED = ['return', 'self', 'old'];

    private readonly NativeTypeConverter $native;

    /** @var array<string, bool|'loading'> per file, during one discovery */
    private array $loaded = [];

    /** @var array<string, true> classes reported under ADR-0012 section 1, by lower-case name */
    private array $reported = [];

    public function __construct(
        private readonly TypeResolver $resolver = new TypeResolver(),
        private readonly GeneratorFactory $generators = new GeneratorFactory(),
    ) {
        $this->native = new NativeTypeConverter(new PhpDocTypeConverter());
    }

    /**
     * @param string $baseDirectory the directory of `pront.php`; relative `paths` and every
     *     `exclude` pattern are read from it
     */
    public function discover(Config $config, string $baseDirectory): DiscoveryResult
    {
        $errors = new PreRunErrors();
        $this->loaded = [];
        $this->reported = [];

        $index = new SourceIndex();
        foreach (SourceFiles::collect($config, $baseDirectory, $errors) as $file) {
            $index->add($file, $errors);
        }

        $contracts = array_values(array_filter(
            $index->classLikes(),
            static fn(ClassLikeDeclaration $c): bool => $c->prontMethods !== [],
        ));
        $related = array_values(array_filter(
            $index->classLikes(),
            static function (ClassLikeDeclaration $d) use ($contracts, $index): bool {
                foreach ($contracts as $contract) {
                    if ($index->descendsFrom($d->name, $contract->name)) {
                        return true;
                    }
                }

                return false;
            },
        ));

        $candidates = array_unique([
            ...array_map(static fn(Placement $p): string => $p->file, $index->placements()),
            ...array_map(static fn(ClassLikeDeclaration $c): string => $c->file, [...$contracts, ...$related]),
        ]);
        sort($candidates, SORT_STRING);
        foreach ($candidates as $file) {
            $this->load($file, $index, $errors);
        }

        foreach ($index->placements() as $placement) {
            $this->readPlacement($placement, $errors);
        }

        $targets = [];
        foreach ($contracts as $contract) {
            // A file whose loading failed may still have declared its classes (PHP binds them
            // early); its targets are not verified on half-run top-level code.
            $failed = ($this->loaded[$contract->file] ?? false) === false;
            if ($failed || !self::declared($contract->name)) {
                foreach (array_keys($contract->prontMethods) as $method) {
                    $errors->add("{$contract->name}::{$method}()", $failed
                        ? "not read, because loading {$contract->file} failed"
                        : "{$contract->name} was not declared by loading {$contract->file}");
                }
                continue;
            }
            $class = new \ReflectionClass($contract->name);
            foreach ($contract->prontMethods as $method => $uses) {
                $target = $this->method($class, $method, $uses, $errors);
                if ($target !== null) {
                    $targets[] = $target;
                }
            }
        }

        $this->checkOverrides($related, $contracts, $errors);

        return new DiscoveryResult($targets, $errors->all(), $this->unverified($related, $contracts));
    }

    /**
     * Loads a candidate file (ADR-0013 section 1), its in-scope parents first. A file whose
     * declarations already exist is not loaded again; one whose declarations exist elsewhere, or
     * that throws while loading, is a pre-run error.
     */
    private function load(string $file, SourceIndex $index, PreRunErrors $errors): bool
    {
        if (isset($this->loaded[$file])) {
            return $this->loaded[$file] !== false;
        }
        $this->loaded[$file] = 'loading';

        $declared = $index->declaredIn($file);
        $existing = [
            ...array_filter($declared['classes'], self::declared(...)),
            ...array_filter($declared['functions'], static fn(string $f): bool => function_exists($f)),
        ];
        $total = count($declared['classes']) + count($declared['functions']);
        if ($existing !== [] && count($existing) === $total) {
            foreach ($declared['classes'] as $class) {
                $elsewhere = (new \ReflectionClass($class))->getFileName();
                if ($elsewhere !== false && realpath($elsewhere) !== $file) {
                    $errors->add($file, "{$class} is already declared in {$elsewhere}");

                    return $this->loaded[$file] = false;
                }
            }

            return $this->loaded[$file] = true;
        }
        if ($existing !== []) {
            $errors->add($file, 'cannot be loaded: ' . implode(', ', $existing) . ' already exist');

            return $this->loaded[$file] = false;
        }

        foreach ($declared['classes'] as $class) {
            foreach ($index->classLike($class)->parents ?? [] as $parent) {
                $declaration = $index->classLike($parent);
                if ($declaration !== null && $declaration->file !== $file && !self::declared($parent)) {
                    $this->load($declaration->file, $index, $errors);
                }
            }
        }

        try {
            (static function (string $__file): void {
                require_once $__file;
            })($file);
        } catch (\Throwable $e) {
            $errors->add($file, "loading failed: " . $e::class . ": {$e->getMessage()}");

            return $this->loaded[$file] = false;
        }

        return $this->loaded[$file] = true;
    }

    /**
     * A Pront Attribute that is not on a method of a named class-like is never a target; reading
     * it lets PHP say why (for example "cannot target function").
     */
    private function readPlacement(Placement $placement, PreRunErrors $errors): void
    {
        if ($placement->reflect === null) {
            foreach ($placement->attributes as $attribute) {
                $errors->add($placement->subject, "#[{$attribute->name}] is not read: {$placement->unreachable}");
            }

            return;
        }

        try {
            $reflector = ($placement->reflect)();
        } catch (\ReflectionException $e) {
            $errors->add($placement->subject, "cannot be read: {$e->getMessage()}");

            return;
        }
        foreach (self::prontAttributes($reflector->getAttributes()) as $attribute) {
            try {
                $attribute->newInstance();
                $errors->add($placement->subject, "#[{$attribute->getName()}] has no effect here in Stage 1");
            } catch (\Throwable $e) {
                $errors->add($placement->subject, "#[{$attribute->getName()}]: {$e->getMessage()}");
            }
        }
    }

    /**
     * @param \ReflectionClass<object> $class
     * @param list<AttributeUse> $uses
     */
    private function method(\ReflectionClass $class, string $name, array $uses, PreRunErrors $errors): ?Target
    {
        $subject = "{$class->getName()}::{$name}()";
        if (!$class->hasMethod($name)) {
            $errors->add($subject, "not found on {$class->getName()} after loading");

            return null;
        }
        $method = $class->getMethod($name);
        $local = new PreRunErrors();

        $verify = null;
        $hasVerify = false;
        $ensures = [];
        $literals = array_values(array_map(
            static fn(AttributeUse $use): bool => $use->closureLiteral,
            array_filter($uses, static fn(AttributeUse $use): bool => $use->is(Ensures::class)),
        ));
        $seen = [];
        foreach (self::prontAttributes($method->getAttributes()) as $attribute) {
            $isEnsures = strcasecmp($attribute->getName(), Ensures::class) === 0;
            $hasVerify = $hasVerify || strcasecmp($attribute->getName(), Verify::class) === 0;
            try {
                $instance = $attribute->newInstance();
            } catch (\Throwable $e) {
                $message = "#[{$attribute->getName()}]: {$e->getMessage()}";
                if (!isset($seen[$message])) {
                    $seen[$message] = true;
                    $local->add($subject, $message);
                }
                $instance = null;
            }
            if ($isEnsures) {
                $ensures[] = [$instance instanceof Ensures ? $instance : null, $literals[count($ensures)] ?? false];
            } elseif ($instance instanceof Verify) {
                $verify = $instance;
            } elseif ($instance !== null) {
                $local->add($subject, "#[{$attribute->getName()}] is not supported in Stage 1");
            }
        }

        if ($class->isTrait()) {
            $local->add($subject, 'a Pront Attribute on a trait method is not supported in Stage 1; put it on the method of the class');
        } elseif ($class->isInterface() || $method->isAbstract()) {
            $this->reported[strtolower($class->getName())] = true;
            $local->add($subject, '#[Verify] or a contract on an interface or abstract method is unsupported until Stage 3 (ADR-0012 section 1)');
        } elseif (($ancestor = self::contractAncestor($method)) !== null) {
            $this->reported[strtolower($class->getName())] = true;
            $local->add($subject, "its parent method {$ancestor} carries #[Verify] or a contract; unsupported until Stage 3 (ADR-0012 section 1)");
        } elseif (!$hasVerify) {
            $local->add($subject, 'a Pront Attribute without #[Verify] on this method or a parent method would verify nothing (ADR-0012 section 1)');
        } else {
            $target = $this->target($class, $method, $verify, $ensures, $subject, $local);
            if ($target !== null && $local->isEmpty()) {
                return $target;
            }
        }
        $errors->addAll($local);

        return null;
    }

    /**
     * @param \ReflectionClass<object> $class
     * @param list<array{?Ensures, bool}> $ensures
     */
    private function target(
        \ReflectionClass $class,
        \ReflectionMethod $method,
        ?Verify $verify,
        array $ensures,
        string $subject,
        PreRunErrors $errors,
    ): ?Target {
        if ($method->isConstructor() || $method->isDestructor()) {
            $errors->add($subject, 'a constructor or destructor is not a Stage 1 target');

            return null;
        }
        if (!$method->isPublic()) {
            $errors->add($subject, 'is not public; Stage 1 verifies public methods only');
        }

        $signature = $this->resolver->resolve($method);
        $errors->addAll($signature->errors);
        $arguments = null;
        if ($signature->errors === []) {
            try {
                $arguments = $this->generators->forSignature($signature);
            } catch (UngeneratableType $e) {
                $errors->add($subject, $e->getMessage());
            }
        }

        $receiver = null;
        if (!$method->isStatic()) {
            try {
                $receiver = $this->generators->forReceiver($class, $this->resolver);
            } catch (UngeneratableType $e) {
                $errors->add($subject, "the receiver cannot be built until Stage 2: {$e->getMessage()}");
            }
        }

        [$conditions, $conflicts] = $this->bind($class, $method, $signature, $ensures, $subject, $errors);

        if ($verify === null || $arguments === null || !$errors->isEmpty()) {
            return null;
        }

        return new Target($method, $verify, $signature, $arguments, $receiver, $conditions, $conflicts);
    }

    /**
     * Binds each condition parameter by name (ADR-0009 section 4, Stage 1 subset of ADR-0014
     * section 3) and reports what cannot bind.
     *
     * @param \ReflectionClass<object> $class
     * @param list<array{?Ensures, bool}> $ensures each with whether it is a closure literal
     * @return array{list<Condition>, list<string>} the conditions and the reserved-name conflicts
     */
    private function bind(
        \ReflectionClass $class,
        \ReflectionMethod $method,
        ResolvedSignature $signature,
        array $ensures,
        string $subject,
        PreRunErrors $errors,
    ): array {
        if ($ensures === []) {
            return [[], []];
        }

        $parameters = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $method->getParameters());
        foreach ($parameters as $parameter) {
            if (in_array($parameter, ['__return', '__self', '__old'], true)) {
                $errors->add("{$subject} parameter \${$parameter}", 'takes a prefixed reserved name, so a condition could not reach the reserved meaning (ADR-0009 section 4)');
            }
        }
        $returnType = $signature->return;
        $returnsNothing = $returnType instanceof VoidType || $returnType instanceof NeverType
            || in_array(strtolower((string) $method->getReturnType()), ['void', 'never'], true);
        $receiverType = $class->isEnum() ? new EnumType($class->getName()) : new ClassType($class->getName());

        $conditions = [];
        $conflicts = [];
        foreach ($ensures as $i => [$instance, $literal]) {
            if ($instance === null) {
                continue;
            }
            $conditionSubject = sprintf('%s #[Ensures] %d', $subject, $i + 1);
            $function = new \ReflectionFunction($instance->condition);
            $bindings = [];
            $failed = false;
            foreach ($function->getParameters() as $parameter) {
                $name = $parameter->getName();
                $where = "{$conditionSubject} parameter \${$name}";
                $fail = static function (string $message) use ($errors, $where, &$failed): void {
                    $errors->add($where, $message);
                    $failed = true;
                };
                if ($parameter->isPassedByReference()) {
                    $fail('a condition parameter cannot be by-reference');
                }
                if ($parameter->isVariadic()) {
                    $fail('a condition parameter cannot be variadic');
                    continue;
                }

                $bound = null;
                $unprefixed = str_starts_with($name, '__') ? substr($name, 2) : $name;
                if (!str_starts_with($name, '__') && in_array($name, $parameters, true)) {
                    $bindings[] = new Binding(BindingKind::Parameter, $name);
                    $bound = $signature->parameters[$name] ?? null;
                    $declared = $parameter->getType();
                    if (in_array($name, self::RESERVED, true)
                        && (!$literal || $declared === null || strtolower((string) $declared) === 'mixed')) {
                        $conflicts[] = "{$where} binds to the method parameter \${$name}, not to the reserved \${$name}; "
                            . "use \$__{$name} for the reserved meaning (ADR-0009 section 4)";
                    }
                } elseif ($unprefixed === 'return' && in_array($name, ['return', '__return'], true)) {
                    if ($returnsNothing) {
                        $fail('a void or never method has no return value to bind');
                    }
                    $bindings[] = new Binding(BindingKind::Return);
                    $bound = $returnType;
                } elseif ($unprefixed === 'self' && in_array($name, ['self', '__self'], true)) {
                    if ($method->isStatic()) {
                        $fail('a static method has no receiver to bind');
                    }
                    $bindings[] = new Binding(BindingKind::Receiver);
                    $bound = $receiverType;
                } elseif ($unprefixed === 'old' && in_array($name, ['old', '__old'], true)) {
                    $fail('$old is supported from Stage 2, when equality ships (ADR-0014 section 3)');
                    continue;
                } else {
                    $fail("is neither a parameter of {$subject} nor a reserved name (\$return, \$self)");
                    continue;
                }

                $declared = $parameter->getType();
                if ($bound !== null && $declared !== null) {
                    $ignored = [];
                    $accepting = $this->native->convert($declared, $function->getClosureScopeClass()?->getName(), $ignored);
                    if ($accepting !== null && self::cannotAccept($bound, $accepting)) {
                        $fail("its type {$declared} cannot accept the bound {$bound->describe()}");
                    }
                }
            }
            if (!$failed) {
                $conditions[] = new Condition($conditionSubject, $instance->condition, $bindings);
            }
        }

        return [$conditions, $conflicts];
    }

    /**
     * Whether a declared parameter type statically cannot accept any value of the bound type. The
     * engine calls conditions from a strict-types file, where only int widens to float.
     */
    private static function cannotAccept(Type $bound, Type $declared): bool
    {
        if (Disjoint::check($bound, $declared) !== Certainty::Yes) {
            return false;
        }
        if (Disjoint::check(new FloatType(), $declared) !== Certainty::No) {
            return true;
        }
        $members = $bound instanceof UnionType ? $bound->members : [$bound];
        $widened = array_map(
            static fn(Type $m): Type => $m instanceof IntType || $m instanceof LiteralType && is_int($m->value) ? new FloatType() : $m,
            $members,
        );

        return Disjoint::check(UnionType::of(...$widened), $declared) === Certainty::Yes;
    }

    /**
     * The overridden or implemented method (parent classes, then interfaces) that carries a Pront
     * Attribute, as `Class::method()`.
     */
    private static function contractAncestor(\ReflectionMethod $method): ?string
    {
        $class = $method->getDeclaringClass();
        $ancestors = [];
        for ($parent = $class->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            $ancestors[] = $parent;
        }
        array_push($ancestors, ...array_values($class->getInterfaces()));
        foreach ($ancestors as $ancestor) {
            if (!$ancestor->hasMethod($method->getName())) {
                continue;
            }
            $theirs = $ancestor->getMethod($method->getName());
            if (!$theirs->isPrivate() && self::prontAttributes($theirs->getAttributes()) !== []) {
                return "{$theirs->getDeclaringClass()->getName()}::{$theirs->getName()}()";
            }
        }

        return null;
    }

    /**
     * ADR-0012 section 1 until Stage 3: a class in scope that overrides or implements, directly or
     * through a trait, a method carrying `#[Verify]` or a contract is a pre-run error.
     *
     * @param list<ClassLikeDeclaration> $related
     * @param list<ClassLikeDeclaration> $contracts
     */
    private function checkOverrides(array $related, array $contracts, PreRunErrors $errors): void
    {
        foreach ($related as $declaration) {
            if (!self::declared($declaration->name)) {
                continue;
            }
            $class = new \ReflectionClass($declaration->name);
            foreach ($contracts as $contract) {
                if (!self::declared($contract->name) || !$class->isSubclassOf($contract->name)) {
                    continue;
                }
                foreach (array_keys($contract->prontMethods) as $name) {
                    if (self::hasCaseless($declaration->prontMethods, $name) || !$class->hasMethod($name)) {
                        continue;
                    }
                    $theirs = new \ReflectionClass($contract->name);
                    if (!$theirs->hasMethod($name) || $theirs->getMethod($name)->isPrivate()) {
                        continue;
                    }
                    // Declared here (directly or through a trait), or inherited from a class
                    // outside the contract's hierarchy (an implementation of an interface method
                    // that a parent supplies). An override between the two is reported on the
                    // class that declares it.
                    $mine = $class->getMethod($name);
                    $from = $mine->getDeclaringClass();
                    if (strcasecmp($from->getName(), $contract->name) !== 0
                        && (strcasecmp($from->getName(), $class->getName()) === 0 || !$from->isSubclassOf($contract->name))) {
                        $this->reported[strtolower($class->getName())] = true;
                        $errors->add(
                            "{$class->getName()}::{$mine->getName()}()",
                            "overrides or implements {$contract->name}::{$name}(), which carries #[Verify] or a contract; "
                                . 'unsupported until Stage 3 (ADR-0012 section 1)',
                        );
                    }
                }
            }
        }
    }

    /**
     * Every class in scope that extends, directly or transitively, a class carrying a `#[Verify]`
     * or contract method, unless already reported as a pre-run error (ADR-0012 section 1).
     *
     * @param list<ClassLikeDeclaration> $related
     * @param list<ClassLikeDeclaration> $contracts
     * @return array<string, string>
     */
    private function unverified(array $related, array $contracts): array
    {
        $listed = [];
        foreach ($related as $declaration) {
            if ($declaration->kind !== 'class' || isset($this->reported[strtolower($declaration->name)])
                || !self::declared($declaration->name)) {
                continue;
            }
            foreach ($contracts as $contract) {
                if ($contract->kind === 'class' && self::declared($contract->name)
                    && is_subclass_of($declaration->name, $contract->name)) {
                    $listed[$declaration->name] = $contract->name;
                    break;
                }
            }
        }
        ksort($listed, SORT_STRING);

        return $listed;
    }

    /**
     * @param list<\ReflectionAttribute<object>> $attributes
     * @return list<\ReflectionAttribute<object>>
     */
    private static function prontAttributes(array $attributes): array
    {
        return array_values(array_filter(
            $attributes,
            static fn(\ReflectionAttribute $a): bool => stripos($a->getName(), SourceIndex::ATTRIBUTE_NAMESPACE) === 0,
        ));
    }

    /**
     * @param array<string, mixed> $map
     */
    private static function hasCaseless(array $map, string $key): bool
    {
        foreach (array_keys($map) as $candidate) {
            if (strcasecmp($candidate, $key) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function declared(string $name): bool
    {
        return class_exists($name, false) || interface_exists($name, false)
            || trait_exists($name, false) || enum_exists($name, false);
    }
}
