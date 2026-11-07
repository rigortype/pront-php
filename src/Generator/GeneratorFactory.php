<?php

declare(strict_types=1);

namespace Pront\Generator;

use Pront\Type\ArrayShapeType;
use Pront\Type\ArrayType;
use Pront\Type\BoolType;
use Pront\Type\EnumType;
use Pront\Type\FloatType;
use Pront\Type\InputPosition;
use Pront\Type\IntType;
use Pront\Type\ListType;
use Pront\Type\LiteralType;
use Pront\Type\NullType;
use Pront\Type\ResolvedSignature;
use Pront\Type\StringType;
use Pront\Type\Type;
use Pront\Type\TypeResolver;
use Pront\Type\UnionType;

/**
 * Synthesizes the generator of a resolved Stage 1 input type (ADR-0014 section 3). A type outside
 * the list is an `UngeneratableType` naming every offending fragment (ADR-0010 section 1), which
 * the caller reports as a pre-run error; nothing is skipped.
 */
final readonly class GeneratorFactory
{
    public function __construct(
        private float $edgeRatio = Defaults::EDGE_RATIO,
    ) {
        if (!($edgeRatio >= 0.0 && $edgeRatio <= 1.0)) {
            throw new \InvalidArgumentException('The edge ratio must be within [0, 1]');
        }
    }

    /**
     * @throws UngeneratableType
     */
    public function forType(Type $type): Generator
    {
        $problems = InputPosition::problems($type);
        if ($problems !== []) {
            throw new UngeneratableType(implode('; ', $problems));
        }

        return $this->build($type, false);
    }

    /**
     * The arguments of a resolved signature. A signature with pre-run errors has no generator.
     *
     * @throws UngeneratableType
     */
    public function forSignature(ResolvedSignature $signature): ArgumentsGenerator
    {
        if ($signature->errors !== []) {
            throw new UngeneratableType(implode('; ', array_map(strval(...), $signature->errors)));
        }
        $parameters = [];
        foreach ($signature->parameters as $name => $type) {
            if ($type === null) {
                throw new \LogicException("{$signature->subject}: parameter \${$name} has no type but no error");
            }
            $parameters[$name] = $this->forType($type);
        }

        return new ArgumentsGenerator($parameters);
    }

    /**
     * The receiver of an instance method (ADR-0014 section 3): an enum yields one drawn case; a
     * class is built through a public constructor whose parameters are Stage 1 types.
     *
     * @param \ReflectionClass<object> $class
     * @throws UngeneratableType
     */
    public function forReceiver(\ReflectionClass $class, TypeResolver $resolver): Generator
    {
        if ($class->isEnum()) {
            /** @var class-string<\UnitEnum> $name */
            $name = $class->getName();

            return $this->forType(new EnumType($name));
        }
        if ($class->isInterface() || $class->isTrait() || $class->isAbstract()) {
            throw new UngeneratableType("{$class->getName()} cannot be instantiated");
        }
        $constructor = $class->getConstructor();
        if ($constructor === null) {
            return new ConstructorGenerator($class->getName(), new ArgumentsGenerator([]));
        }
        if (!$constructor->isPublic()) {
            throw new UngeneratableType("{$class->getName()}::__construct() is not public");
        }

        return new ConstructorGenerator($class->getName(), $this->forSignature($resolver->resolve($constructor)));
    }

    private function build(Type $type, bool $arrayKey): Generator
    {
        return match (true) {
            $type instanceof IntType => new IntGenerator($type->min, $type->max, $this->edgeRatio),
            $type instanceof FloatType => new FloatGenerator($this->edgeRatio),
            $type instanceof BoolType => new BoolGenerator($this->edgeRatio),
            $type instanceof StringType => new StringGenerator($type->nonEmpty, $arrayKey, $this->edgeRatio),
            $type instanceof LiteralType => new ConstantGenerator($type->value),
            $type instanceof NullType => new ConstantGenerator(null),
            $type instanceof EnumType => new EnumGenerator($type->name),
            $type instanceof UnionType => $this->union($type, $arrayKey),
            $type instanceof ListType => new ListGenerator($this->build($type->value, false), $type->nonEmpty, $this->edgeRatio),
            $type instanceof ArrayType => $this->array($type),
            $type instanceof ArrayShapeType => new ShapeGenerator(array_map(
                fn(Type $item): Generator => $this->build($item, false),
                $type->items,
            )),
            default => throw new UngeneratableType("{$type->describe()} has no Stage 1 generator"),
        };
    }

    private function union(UnionType $type, bool $arrayKey): Generator
    {
        $members = [];
        $nullable = false;
        foreach ($type->members as $member) {
            if ($member instanceof NullType) {
                $nullable = true;
            } else {
                $members[] = $this->build($member, $arrayKey);
            }
        }
        if ($members === []) {
            throw new UngeneratableType("{$type->describe()} has no member other than null");
        }

        return new UnionGenerator($nullable, $members);
    }

    private function array(ArrayType $type): Generator
    {
        $keyMembers = $type->key instanceof UnionType ? $type->key->members : [$type->key];
        foreach ($keyMembers as $member) {
            $isKey = $member instanceof IntType
                || $member instanceof StringType
                || ($member instanceof LiteralType && (is_int($member->value) || is_string($member->value)));
            if (!$isKey) {
                throw new UngeneratableType("{$type->describe()}: the key type must be int, string or int|string");
            }
        }

        return new ArrayGenerator(
            $this->build($type->key, true),
            static function (int $key) use ($keyMembers): bool {
                foreach ($keyMembers as $member) {
                    if (($member instanceof IntType && $key >= $member->min && $key <= $member->max)
                        || ($member instanceof LiteralType && $member->value === $key)
                    ) {
                        return true;
                    }
                }

                return false;
            },
            $this->build($type->value, false),
            $this->edgeRatio,
        );
    }
}
