<?php

declare(strict_types=1);

namespace Pront\Discovery;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Pront\PreRun\PreRunErrors;

/**
 * The static side of discovery (ADR-0013 section 1): parses the files in scope with
 * nikic/php-parser, without loading them, and records every named class-like with its parents
 * (the parent-to-implementation index), every Pront Attribute, and what each file declares.
 */
final class SourceIndex
{
    public const string ATTRIBUTE_NAMESPACE = 'Pront\\Attribute\\';

    private readonly Parser $parser;

    /** @var array<string, ClassLikeDeclaration> by lower-case name */
    private array $classLikes = [];

    /** @var list<Placement> */
    private array $placements = [];

    /** @var array<string, string> file => why it is not loaded */
    private array $unloadable = [];

    /** @var list<array{string, string, int}> */
    private array $suspects = [];

    /** @var array<string, array{classes: list<string>, functions: list<string>}> by file */
    private array $declarations = [];

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Parses one file. A file that does not parse is a pre-run error: it might carry a `#[Verify]`.
     */
    public function add(string $file, PreRunErrors $errors): void
    {
        $code = @file_get_contents($file);
        if ($code === false) {
            $errors->add($file, 'cannot be read');

            return;
        }
        try {
            $ast = $this->parser->parse($code) ?? [];
        } catch (\PhpParser\Error $e) {
            $errors->add($file, "does not parse: {$e->getMessage()}");

            return;
        }

        $traverser = new NodeTraverser(new NameResolver());
        $ast = $traverser->traverse($ast);
        $collector = new class ($file) extends NodeVisitorAbstract {
            /** @var list<ClassLikeDeclaration> */
            public array $classLikes = [];

            /** @var list<Placement> */
            public array $placements = [];

            /** @var list<string> */
            public array $functions = [];

            /** @var list<Node> class-likes (named or anonymous) and function-likes being visited */
            private array $stack = [];

            public function __construct(private readonly string $file)
            {
            }

            public function enterNode(Node $node): null
            {
                $this->collect($node);
                if ($node instanceof Node\Stmt\ClassLike || $node instanceof Node\FunctionLike) {
                    $this->stack[] = $node;
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\ClassLike || $node instanceof Node\FunctionLike) {
                    array_pop($this->stack);
                }

                return null;
            }

            private function collect(Node $node): void
            {
                if ($node instanceof Node\Stmt\Function_) {
                    $this->functions[] = (string) $node->namespacedName;
                }
                if ($node instanceof Node\Stmt\ClassLike && $node->name !== null) {
                    $this->classLikes[] = $this->declaration($node);
                }

                $attributes = property_exists($node, 'attrGroups') ? SourceIndex::prontAttributes($node->attrGroups) : [];
                if ($attributes === [] || $node instanceof Node\Stmt\ClassMethod && $this->namedClass() !== null) {
                    return;
                }
                $this->placements[] = $this->placement($node, $attributes);
            }

            private function declaration(Node\Stmt\ClassLike $node): ClassLikeDeclaration
            {
                $parents = match (true) {
                    $node instanceof Node\Stmt\Class_ => [...($node->extends === null ? [] : [$node->extends]), ...$node->implements],
                    $node instanceof Node\Stmt\Interface_ => $node->extends,
                    $node instanceof Node\Stmt\Enum_ => $node->implements,
                    default => [],
                };
                foreach ($node->getTraitUses() as $use) {
                    array_push($parents, ...$use->traits);
                }
                $methods = [];
                foreach ($node->getMethods() as $method) {
                    $attributes = SourceIndex::prontAttributes($method->attrGroups);
                    if ($attributes !== []) {
                        $methods[$method->name->toString()] = $attributes;
                    }
                }

                return new ClassLikeDeclaration(
                    (string) $node->namespacedName,
                    match (true) {
                        $node instanceof Node\Stmt\Interface_ => 'interface',
                        $node instanceof Node\Stmt\Trait_ => 'trait',
                        $node instanceof Node\Stmt\Enum_ => 'enum',
                        default => 'class',
                    },
                    $this->file,
                    $node->getStartLine(),
                    array_map(static fn(Node\Name $name): string => $name->toString(), $parents),
                    $methods,
                );
            }

            /**
             * @param list<AttributeUse> $attributes
             */
            private function placement(Node $node, array $attributes): Placement
            {
                $line = $node->getStartLine();
                $where = "{$this->file}:{$line}";
                if ($this->inAnonymousClass() || $node instanceof Node\Stmt\Class_ && $node->name === null) {
                    return new Placement("anonymous class at {$where}", $this->file, $attributes, null, 'anonymous classes are out of scope (ADR-0012 section 1)');
                }
                $class = $this->namedClass();
                $function = $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];

                return match (true) {
                    $node instanceof Node\Stmt\ClassLike => new Placement(
                        (string) $node->namespacedName,
                        $this->file,
                        $attributes,
                        static fn(): \ReflectionClass => new \ReflectionClass((string) $node->namespacedName),
                    ),
                    $node instanceof Node\Stmt\Function_ => new Placement(
                        "{$node->namespacedName}()",
                        $this->file,
                        $attributes,
                        static fn(): \ReflectionFunction => new \ReflectionFunction((string) $node->namespacedName),
                    ),
                    $node instanceof Node\Expr\Closure, $node instanceof Node\Expr\ArrowFunction => new Placement(
                        "closure at {$where}",
                        $this->file,
                        $attributes,
                        null,
                        'a closure is not a Stage 1 target',
                    ),
                    $node instanceof Node\Param && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)
                        && ($function instanceof Node\Stmt\Function_ || $function instanceof Node\Stmt\ClassMethod && $class !== null) => $this->parameter($node->var->name, $function, $class, $attributes),
                    $node instanceof Node\Stmt\Property && $class !== null => new Placement(
                        "{$class}::\${$node->props[0]->name}",
                        $this->file,
                        $attributes,
                        static fn(): \ReflectionProperty => new \ReflectionProperty($class, $node->props[0]->name->toString()),
                    ),
                    ($node instanceof Node\Stmt\ClassConst || $node instanceof Node\Stmt\EnumCase) && $class !== null => new Placement(
                        $class . '::' . ($node instanceof Node\Stmt\ClassConst ? $node->consts[0]->name : $node->name),
                        $this->file,
                        $attributes,
                        static fn(): \ReflectionClassConstant => new \ReflectionClassConstant(
                            $class,
                            ($node instanceof Node\Stmt\ClassConst ? $node->consts[0]->name : $node->name)->toString(),
                        ),
                    ),
                    $node instanceof Node\Stmt\Const_ => new Placement(
                        (string) $node->consts[0]->namespacedName,
                        $this->file,
                        $attributes,
                        static fn(): \ReflectionConstant => new \ReflectionConstant((string) $node->consts[0]->namespacedName),
                    ),
                    default => new Placement(
                        $node->getType() . " at {$where}",
                        $this->file,
                        $attributes,
                        null,
                        'Pront reads no Attribute here',
                    ),
                };
            }

            /**
             * @param list<AttributeUse> $attributes
             */
            private function parameter(string $name, Node\Stmt\Function_|Node\Stmt\ClassMethod $function, ?string $class, array $attributes): Placement
            {
                if ($function instanceof Node\Stmt\Function_) {
                    $functionName = (string) $function->namespacedName;

                    return new Placement(
                        "{$functionName}() parameter \${$name}",
                        $this->file,
                        $attributes,
                        static fn(): \ReflectionParameter => new \ReflectionParameter($functionName, $name),
                    );
                }
                $method = $function->name->toString();

                return new Placement(
                    "{$class}::{$method}() parameter \${$name}",
                    $this->file,
                    $attributes,
                    static fn(): \ReflectionParameter => new \ReflectionParameter([$class, $method], $name),
                );
            }

            private function namedClass(): ?string
            {
                for ($i = count($this->stack) - 1; $i >= 0; $i--) {
                    $node = $this->stack[$i];
                    if ($node instanceof Node\Stmt\ClassLike) {
                        return $node->name === null ? null : (string) $node->namespacedName;
                    }
                }

                return null;
            }

            private function inAnonymousClass(): bool
            {
                foreach ($this->stack as $node) {
                    if ($node instanceof Node\Stmt\Class_ && $node->name === null) {
                        return true;
                    }
                }

                return false;
            }
        };
        (new NodeTraverser($collector))->traverse($ast);

        $classes = [];
        foreach ($collector->classLikes as $declaration) {
            $key = strtolower($declaration->name);
            $existing = $this->classLikes[$key] ?? null;
            if ($existing === null) {
                $this->classLikes[$key] = $declaration;
                $classes[] = $declaration->name;
                continue;
            }
            // Loading a file that redeclares a loaded class is an uncatchable fatal, and with two
            // declarations in one file Pront cannot tell which one runs: the file is not loaded,
            // and the Pront Attributes of every declaration are kept so they are reported.
            if ($existing->file === $file) {
                $this->unloadable[$file] = "declares {$declaration->name} more than once; Pront cannot tell which declaration runs";
                $this->classLikes[$key] = new ClassLikeDeclaration(
                    $existing->name,
                    $existing->kind,
                    $existing->file,
                    $existing->line,
                    $existing->parents,
                    $existing->prontMethods + $declaration->prontMethods,
                );
            } else {
                $errors->add($declaration->name, "is declared in both {$existing->file} and {$file}");
                $this->unloadable[$file] = "redeclares {$declaration->name}, already declared in {$existing->file}";
            }
        }
        array_push($this->placements, ...$collector->placements);
        $this->declarations[$file] = ['classes' => $classes, 'functions' => $collector->functions];

        foreach ((new NodeFinder())->findInstanceOf($ast, Node\Attribute::class) as $attribute) {
            $this->checkAttribute($attribute, $file);
        }
    }

    /**
     * Two things about any Attribute, found before loading. A non-static closure or an arrow
     * function in its arguments is a compile-time fatal that would end the run (ADR-0009 section
     * 4), so the file is not loaded. A `#[Verify]` or `#[Ensures]` that does not resolve to
     * `Pront\Attribute\` (a missing `use` import) is recorded, so it is reported when the class
     * it names does not exist rather than silently ignored.
     */
    private function checkAttribute(Node\Attribute $attribute, string $file): void
    {
        foreach ($attribute->args as $arg) {
            $invalid = self::invalidClosure($arg);
            if ($invalid !== null) {
                $this->unloadable[$file] ??= sprintf(
                    'line %d: an Attribute argument holds %s; PHP allows only static function closures there, and loading the file would be a fatal error',
                    $invalid->getStartLine(),
                    $invalid instanceof Node\Expr\ArrowFunction ? 'an arrow function' : 'a closure that is not static',
                );
            }
        }

        $name = $attribute->name->toString();
        $short = strtolower(substr($name, (int) strrpos('\\' . $name, '\\')));
        if (in_array($short, ['verify', 'ensures'], true) && stripos($name, self::ATTRIBUTE_NAMESPACE) !== 0) {
            $this->suspects[] = [$name, $file, $attribute->getStartLine()];
        }
    }

    /**
     * The first arrow function or non-static closure in a constant expression; the body of a
     * static closure is ordinary code and is not searched.
     */
    private static function invalidClosure(Node $node): ?Node
    {
        if ($node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Expr\Closure && !$node->static) {
            return $node;
        }
        foreach ($node->getSubNodeNames() as $name) {
            if ($node instanceof Node\Expr\Closure && $name === 'stmts') {
                continue;
            }
            $children = $node->{$name};
            foreach (is_array($children) ? $children : [$children] as $child) {
                if ($child instanceof Node && ($found = self::invalidClosure($child)) !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Why a file in scope must not be loaded, or null.
     */
    public function unloadable(string $file): ?string
    {
        return $this->unloadable[$file] ?? null;
    }

    /**
     * Attributes named like Pront's that resolve elsewhere: name, file, line.
     *
     * @return list<array{string, string, int}>
     */
    public function suspects(): array
    {
        return $this->suspects;
    }

    /**
     * @param array<Node\AttributeGroup> $groups
     * @return list<AttributeUse>
     */
    public static function prontAttributes(array $groups): array
    {
        $uses = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                $name = $attribute->name->toString();
                if (stripos($name, self::ATTRIBUTE_NAMESPACE) !== 0) {
                    continue;
                }
                $condition = null;
                foreach ($attribute->args as $position => $arg) {
                    if ($arg instanceof Node\Arg && ($arg->name?->toString() === 'condition' || $arg->name === null && $position === 0)) {
                        $condition = $arg->value;
                    }
                }
                $uses[] = new AttributeUse($name, $attribute->getStartLine(), $condition instanceof Node\Expr\Closure);
            }
        }

        return $uses;
    }

    public function classLike(string $name): ?ClassLikeDeclaration
    {
        return $this->classLikes[strtolower(ltrim($name, '\\'))] ?? null;
    }

    /**
     * @return list<ClassLikeDeclaration> in file order
     */
    public function classLikes(): array
    {
        return array_values($this->classLikes);
    }

    /**
     * @return list<Placement>
     */
    public function placements(): array
    {
        return $this->placements;
    }

    /**
     * @return array{classes: list<string>, functions: list<string>}
     */
    public function declaredIn(string $file): array
    {
        return $this->declarations[$file] ?? ['classes' => [], 'functions' => []];
    }

    /**
     * Whether a parent chain of `$name` reaches a class-like that is neither in the index nor
     * already declared by PHP itself, so where it leads is known only after loading.
     */
    public function leavesIndex(string $name): bool
    {
        $seen = [];
        $pending = [$name];
        while ($pending !== []) {
            $current = array_pop($pending);
            $declaration = $this->classLike($current);
            if ($declaration === null) {
                $known = class_exists($current, false) || interface_exists($current, false) || trait_exists($current, false);
                if (!$known || !(new \ReflectionClass($current))->isInternal()) {
                    return true;
                }
                continue;
            }
            if (isset($seen[strtolower($declaration->name)])) {
                continue;
            }
            $seen[strtolower($declaration->name)] = true;
            array_push($pending, ...$declaration->parents);
        }

        return false;
    }

    /**
     * Whether `$name` reaches `$ancestor` through parents recorded in the index. A chain that
     * leaves the index (a parent outside `paths`) cannot be followed statically.
     */
    public function descendsFrom(string $name, string $ancestor): bool
    {
        $seen = [];
        $pending = [$name];
        while ($pending !== []) {
            $current = $this->classLike(array_pop($pending));
            if ($current === null || isset($seen[strtolower($current->name)])) {
                continue;
            }
            $seen[strtolower($current->name)] = true;
            foreach ($current->parents as $parent) {
                if (strcasecmp($parent, $ancestor) === 0) {
                    return true;
                }
                $pending[] = $parent;
            }
        }

        return false;
    }
}
