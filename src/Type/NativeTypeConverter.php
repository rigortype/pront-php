<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * Turns a native (reflected) type into Pront's model. `iterable`, `object`, `callable` and
 * intersection types are outside the Stage 1 list and become `UncheckedType` fragments; an unknown
 * class adds a message to `$errors` and yields null.
 */
final class NativeTypeConverter
{
    public function __construct(
        private readonly PhpDocTypeConverter $classes,
    ) {
    }

    /**
     * @param ?class-string $class the declaring class, for `self`, `static` and `parent`
     * @param list<string> $errors
     */
    public function convert(\ReflectionType $type, ?string $class, array &$errors): ?Type
    {
        if ($type instanceof \ReflectionUnionType) {
            $members = [];
            $failed = false;
            foreach ($type->getTypes() as $member) {
                $converted = $this->convert($member, $class, $errors);
                if ($converted === null) {
                    $failed = true;
                } else {
                    $members[] = $converted;
                }
            }

            if ($failed || $members === []) {
                return null;
            }

            // Reflection reports `iterable` inside a union as `Traversable|array`; fold it back.
            $names = array_map(static fn(Type $m): string => $m->describe(), $members);
            $traversable = array_search(\Traversable::class, $names, true);
            $array = array_search('array', $names, true);
            if ($traversable !== false && $array !== false) {
                unset($members[$traversable], $members[$array]);
                $members[] = new UncheckedType('iterable', 'not in the Stage 1 list');
            }

            return UnionType::of(...array_values($members));
        }

        if ($type instanceof \ReflectionIntersectionType) {
            return new UncheckedType((string) $type, 'intersection types are not in the Stage 1 list');
        }

        if (!$type instanceof \ReflectionNamedType) {
            return new UncheckedType((string) $type, 'not in the Stage 1 list');
        }

        $converted = $this->named($type, $class, $errors);
        if ($converted === null) {
            return null;
        }

        $name = strtolower($type->getName());

        return $type->allowsNull() && $name !== 'null' && $name !== 'mixed'
            ? UnionType::of($converted, new NullType())
            : $converted;
    }

    /**
     * @param ?class-string $class
     * @param list<string> $errors
     */
    private function named(\ReflectionNamedType $type, ?string $class, array &$errors): ?Type
    {
        $name = $type->getName();
        if (!$type->isBuiltin()) {
            $keyword = strtolower($name);
            // PHP 8.5 reflection already reports native `self` and `parent` as class names; only
            // `static` reaches this branch in practice.
            if (in_array($keyword, ['self', 'static', 'parent'], true)) {
                $parent = $class === null ? false : get_parent_class($class);

                return match (true) {
                    $class === null => self::error($errors, "{$keyword} is used outside a class"),
                    $keyword === 'self' => new SelfType($class),
                    $keyword === 'static' => new StaticType($class),
                    $parent === false => self::error($errors, "parent is used in {$class}, which has no parent class"),
                    default => new ClassType($parent),
                };
            }

            return $this->classes->className($name, $errors);
        }

        return match ($name) {
            'int' => new IntType(),
            'float' => new FloatType(),
            'bool' => new BoolType(),
            'true' => new LiteralType(true),
            'false' => new LiteralType(false),
            'string' => new StringType(),
            'null' => new NullType(),
            'mixed' => new MixedType(),
            'void' => new VoidType(),
            'never' => new NeverType(),
            'array' => ArrayType::native(),
            default => new UncheckedType($name, 'not in the Stage 1 list'),
        };
    }

    /**
     * @param list<string> $errors
     */
    private static function error(array &$errors, string $message): null
    {
        $errors[] = $message;

        return null;
    }
}
