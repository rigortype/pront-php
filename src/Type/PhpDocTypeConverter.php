<?php

declare(strict_types=1);

namespace Pront\Type;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\InvalidTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\OffsetAccessTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;

/**
 * Turns a parsed PHPDoc type into Pront's model (ADR-0003 items 2 and 3), position-agnostic:
 *
 * - a construct ADR-0003 item 3 lists as a pre-run error (type aliases, non-literal integer-range
 *   bounds, enum cases and other constant expressions, shapes beyond the Stage 1 list), and a
 *   malformed or unresolvable declaration, adds a message to `$errors` and yields null;
 * - any other construct outside the Stage 1 list yields an `UncheckedType` fragment, which the
 *   caller reports as unchecked (return position) or as a pre-run error (input position).
 */
final class PhpDocTypeConverter
{
    /**
     * PHPStan built-in type keywords outside the Stage 1 list: recognized so they are reported as
     * unsupported rather than looked up as class names.
     */
    private const array OTHER_KEYWORDS = [
        'non-positive-int', 'non-zero-int', 'lowercase-string', 'uppercase-string', 'literal-string',
        'class-string', 'interface-string', 'trait-string', 'enum-string', 'callable-string',
        'array-key', 'scalar', 'empty-scalar', 'non-empty-scalar', 'number', 'numeric',
        'numeric-string', 'non-empty-lowercase-string', 'non-empty-uppercase-string', 'truthy-string',
        'non-falsy-string', 'non-empty-literal-string', 'associative-array', 'non-empty-array',
        'iterable', 'callable', 'pure-callable', 'pure-closure', 'resource', 'closed-resource',
        'open-resource', 'object', 'callable-object', 'callable-array', 'non-empty-mixed', 'empty',
        '__always-list', 'key-of', 'value-of', 'int-mask', 'int-mask-of', 'template-type', 'new',
        'max', 'min',
    ];

    /**
     * @param list<string> $errors
     */
    public function convert(TypeNode $node, NameScope $scope, array &$errors): ?Type
    {
        return match (true) {
            $node instanceof IdentifierTypeNode => $this->identifier($node->name, $scope, $errors),
            $node instanceof NullableTypeNode => $this->union([$node->type, new IdentifierTypeNode('null')], $scope, $errors),
            $node instanceof UnionTypeNode => $this->union($node->types, $scope, $errors),
            $node instanceof GenericTypeNode => $this->generic($node, $scope, $errors),
            $node instanceof ConstTypeNode => $this->constant($node, $scope, $errors),
            $node instanceof ArrayShapeNode => $this->shape($node, $scope, $errors),
            $node instanceof ObjectShapeNode => $this->error($errors, "object shape {$node} is not supported in Stage 1"),
            $node instanceof InvalidTypeNode => $this->error($errors, "type {$node} does not parse"),
            $node instanceof ArrayTypeNode => new UncheckedType(
                self::written($node),
                "not in the Stage 1 list; write list<{$node->type}> or array<int|string, {$node->type}>",
            ),
            $node instanceof IntersectionTypeNode => self::unsupported($node, 'intersection types are not in the Stage 1 list'),
            $node instanceof CallableTypeNode => self::unsupported($node, 'callable types are not in the Stage 1 list'),
            $node instanceof ThisTypeNode => self::unsupported($node, 'not in the Stage 1 list'),
            $node instanceof ConditionalTypeNode, $node instanceof ConditionalTypeForParameterNode =>
                self::unsupported($node, 'conditional types are not in the Stage 1 list'),
            $node instanceof OffsetAccessTypeNode => self::unsupported($node, 'offset access types are not in the Stage 1 list'),
            default => self::unsupported($node, 'not in the Stage 1 list'),
        };
    }

    /**
     * @param list<string> $errors
     */
    private function identifier(string $name, NameScope $scope, array &$errors): ?Type
    {
        $keyword = strtolower($name);
        $type = match ($keyword) {
            'int', 'integer' => new IntType(),
            'positive-int' => new IntType(1),
            'negative-int' => new IntType(PHP_INT_MIN, -1),
            'non-negative-int' => new IntType(0),
            'float', 'double' => new FloatType(),
            'bool', 'boolean' => new BoolType(),
            'true' => new LiteralType(true),
            'false' => new LiteralType(false),
            'string' => new StringType(),
            'non-empty-string' => new StringType(true),
            'null' => new NullType(),
            'mixed' => new MixedType(),
            'void' => new VoidType(),
            'never', 'noreturn', 'never-return', 'never-returns', 'no-return' => new NeverType(),
            'list' => new ListType(new MixedType()),
            'non-empty-list' => new ListType(new MixedType(), true),
            'array' => ArrayType::native(),
            default => null,
        };
        if ($type !== null) {
            return $type;
        }

        if (in_array($keyword, self::OTHER_KEYWORDS, true)) {
            return new UncheckedType($name, 'not in the Stage 1 list');
        }

        if (in_array($keyword, ['self', 'static', 'parent'], true)) {
            return $this->relativeClass($keyword, $scope, $errors);
        }

        if (isset($scope->aliases[$name])) {
            return $this->error(
                $errors,
                "type alias {$name} ({$scope->aliases[$name]}) is not supported in Stage 1",
            );
        }

        if (in_array($name, $scope->templates, true)) {
            return new UncheckedType($name, 'template types are not in the Stage 1 list');
        }

        return $this->className($scope->resolveClassName($name), $errors);
    }

    /**
     * @param list<string> $errors
     */
    private function relativeClass(string $keyword, NameScope $scope, array &$errors): ?Type
    {
        if ($scope->class === null) {
            return $this->error($errors, "{$keyword} is used outside a class");
        }

        if ($keyword === 'self') {
            return new SelfType($scope->class);
        }
        if ($keyword === 'static') {
            return new StaticType($scope->class);
        }

        $parent = get_parent_class($scope->class);
        if ($parent === false) {
            return $this->error($errors, "parent is used in {$scope->class}, which has no parent class");
        }

        return new ClassType($parent);
    }

    /**
     * A resolved class-like name: an enum, or a class or interface. Anything else cannot be
     * resolved and is a pre-run error.
     *
     * @param list<string> $errors
     */
    public function className(string $name, array &$errors): ?Type
    {
        if (enum_exists($name)) {
            return new EnumType((new \ReflectionEnum($name))->getName());
        }
        if (class_exists($name) || interface_exists($name)) {
            return new ClassType((new \ReflectionClass($name))->getName());
        }
        if (trait_exists($name)) {
            return $this->error($errors, "{$name} is a trait, not a type");
        }

        return $this->error($errors, "unknown class {$name}");
    }

    /**
     * @param array<TypeNode> $nodes
     * @param list<string> $errors
     */
    private function union(array $nodes, NameScope $scope, array &$errors): ?Type
    {
        $members = [];
        $failed = false;
        foreach ($nodes as $node) {
            $member = $this->convert($node, $scope, $errors);
            if ($member === null) {
                $failed = true;
            } else {
                $members[] = $member;
            }
        }

        return $failed || $members === [] ? null : UnionType::of(...$members);
    }

    /**
     * @param list<string> $errors
     */
    private function generic(GenericTypeNode $node, NameScope $scope, array &$errors): ?Type
    {
        $base = strtolower($node->type->name);
        $arguments = $node->genericTypes;

        if ($base === 'int') {
            return $this->intRange($node, $errors);
        }

        if ($base === 'list' || $base === 'non-empty-list') {
            if (count($arguments) !== 1) {
                return $this->error($errors, "{$node} takes exactly one type argument");
            }
            $value = $this->convert($arguments[0], $scope, $errors);

            return $value === null ? null : new ListType($value, $base === 'non-empty-list');
        }

        if ($base === 'array') {
            if (count($arguments) !== 2) {
                return new UncheckedType(
                    (string) $node,
                    'not in the Stage 1 list; write array<K, V> with an explicit key type (int, string or int|string)',
                );
            }
            $key = $this->convert($arguments[0], $scope, $errors);
            $value = $this->convert($arguments[1], $scope, $errors);
            if ($key === null || $value === null) {
                return null;
            }
            if (!in_array($key->describe(), ['int', 'string', 'int|string', 'string|int'], true)) {
                return new UncheckedType(
                    (string) $node,
                    "array key type {$arguments[0]} is not in the Stage 1 list (int, string or int|string)",
                );
            }

            return new ArrayType($key, $value);
        }

        $failed = false;
        foreach ($node->genericTypes as $argument) {
            $failed = $this->convert($argument, $scope, $errors) === null || $failed;
        }

        $isKeyword = in_array($base, self::OTHER_KEYWORDS, true)
            || in_array($base, ['self', 'static', 'parent'], true);
        if (!$isKeyword && $this->className($scope->resolveClassName($node->type->name), $errors) === null) {
            return null;
        }

        return $failed ? null : self::unsupported(
            $node,
            $isKeyword
                ? 'not in the Stage 1 list'
                : 'generic class types are not in the Stage 1 list',
        );
    }

    /**
     * `int<a, b>`: each bound is an integer literal, `min` or `max` (ADR-0003 item 3).
     *
     * @param list<string> $errors
     */
    private function intRange(GenericTypeNode $node, array &$errors): ?Type
    {
        if (count($node->genericTypes) !== 2) {
            return $this->error($errors, "{$node} takes exactly two bounds");
        }

        $bounds = [];
        foreach ($node->genericTypes as $i => $bound) {
            $value = match (true) {
                $bound instanceof IdentifierTypeNode && $bound->name === ($i === 0 ? 'min' : 'max') =>
                    $i === 0 ? PHP_INT_MIN : PHP_INT_MAX,
                $bound instanceof ConstTypeNode && $bound->constExpr instanceof ConstExprIntegerNode =>
                    IntegerLiteral::parse($bound->constExpr->value),
                default => false,
            };
            if ($value === false) {
                $errors[] = "integer-range bound {$bound} in {$node} is not an integer literal, "
                    . ($i === 0 ? 'min' : 'max') . '; constant expressions are not supported in Stage 1';
            } elseif ($value === null) {
                $errors[] = "integer-range bound {$bound} in {$node} is outside PHP's int range";
            } else {
                $bounds[] = $value;
            }
        }

        if (count($bounds) !== 2) {
            return null;
        }
        if ($bounds[0] > $bounds[1]) {
            return $this->error($errors, "integer range {$node} is empty");
        }

        return new IntType($bounds[0], $bounds[1]);
    }

    /**
     * @param list<string> $errors
     */
    private function constant(ConstTypeNode $node, NameScope $scope, array &$errors): ?Type
    {
        $expr = $node->constExpr;
        if ($expr instanceof ConstExprIntegerNode) {
            $value = IntegerLiteral::parse($expr->value);

            return $value === null
                ? $this->error($errors, "integer literal {$expr} is outside PHP's int range")
                : new LiteralType($value);
        }
        if ($expr instanceof ConstExprFloatNode) {
            return new LiteralType((float) str_replace('_', '', $expr->value));
        }
        if ($expr instanceof ConstExprStringNode) {
            return new LiteralType($expr->value);
        }
        if ($expr instanceof ConstFetchNode && $expr->className !== '') {
            $class = in_array(strtolower($expr->className), ['self', 'static'], true) && $scope->class !== null
                ? $scope->class
                : $scope->resolveClassName($expr->className);
            if (enum_exists($class)) {
                return $this->error($errors, "enum case reference {$expr} is not supported in Stage 1");
            }
        }

        return $this->error($errors, "constant expression {$expr} is not supported in Stage 1");
    }

    /**
     * @param list<string> $errors
     */
    private function shape(ArrayShapeNode $node, NameScope $scope, array &$errors): ?Type
    {
        $problems = [];
        if ($node->kind !== ArrayShapeNode::KIND_ARRAY) {
            $problems[] = "{$node->kind}{...} shapes are not supported";
        }
        if (!$node->sealed) {
            $problems[] = 'unsealed shapes (...) are not supported';
        }
        foreach ($node->items as $item) {
            if ($item->optional) {
                $problems[] = "optional key {$item->keyName}? is not supported";
            }
        }
        if ($problems !== []) {
            return $this->error($errors, "array shape {$node}: " . implode('; ', $problems) . ' in Stage 1');
        }

        $items = [];
        $nextIndex = 0;
        $failed = false;
        foreach ($node->items as $item) {
            $key = match (true) {
                $item->keyName === null => $nextIndex,
                $item->keyName instanceof ConstExprIntegerNode => IntegerLiteral::parse($item->keyName->value),
                $item->keyName instanceof ConstExprStringNode => self::normalizeKey($item->keyName->value),
                $item->keyName instanceof IdentifierTypeNode => self::normalizeKey($item->keyName->name),
                default => null,
            };
            if ($key === null) {
                $errors[] = "array shape {$node}: key {$item->keyName} is not an int or string literal";
                $failed = true;
                continue;
            }
            if (array_key_exists($key, $items)) {
                $errors[] = "array shape {$node}: duplicate key {$item->keyName}";
                $failed = true;
                continue;
            }
            if (is_int($key) && $key >= $nextIndex) {
                $nextIndex = $key === PHP_INT_MAX ? $key : $key + 1;
            }
            $value = $this->convert($item->valueType, $scope, $errors);
            if ($value === null) {
                $failed = true;
                continue;
            }
            $items[$key] = $value;
        }

        return $failed ? null : new ArrayShapeType($items);
    }

    /**
     * PHP turns a canonical decimal integer string key within the int range into an int key.
     */
    private static function normalizeKey(string $key): int|string
    {
        $int = filter_var($key, FILTER_VALIDATE_INT);

        return $int !== false && (string) $int === $key ? $int : $key;
    }

    private static function unsupported(TypeNode $node, string $reason): UncheckedType
    {
        return new UncheckedType(self::written($node), $reason);
    }

    /**
     * A type node as PHPDoc text, without the parentheses the parser's printer puts around a
     * top-level union or intersection.
     */
    public static function written(TypeNode $node): string
    {
        $text = (string) $node;

        return ($node instanceof UnionTypeNode || $node instanceof IntersectionTypeNode)
            && str_starts_with($text, '(') && str_ends_with($text, ')')
            ? substr($text, 1, -1)
            : $text;
    }

    /**
     * @param list<string> $errors
     */
    private function error(array &$errors, string $message): null
    {
        $errors[] = $message;

        return null;
    }
}
