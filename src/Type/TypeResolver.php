<?php

declare(strict_types=1);

namespace Pront\Type;

use PHPStan\PhpDocParser\Ast\PhpDoc\InvalidTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ThrowsTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypeAliasImportTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypeAliasTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Pront\PreRun\PreRunErrors;

/**
 * The Stage 1 type resolver (ADR-0003): resolves a function's or method's parameter types, return
 * type and `@throws` from its native types and PHPDoc, and collects every pre-run error instead of
 * stopping at the first.
 */
final class TypeResolver
{
    /** Read in this order; the last one present wins (PHPStan's precedence, ADR-0003 item 3). */
    public const array PARAM_TAGS = ['@param', '@phan-param', '@psalm-param', '@phpstan-param'];

    /** Read in this order; the last one present wins. */
    public const array RETURN_TAGS = ['@return', '@phan-return', '@phan-real-return', '@psalm-return', '@phpstan-return'];

    private const array ALIAS_TAGS = [
        '@phpstan-type', '@psalm-type', '@phan-type', '@phpstan-import-type', '@psalm-import-type',
    ];

    private readonly Lexer $lexer;
    private readonly PhpDocParser $phpDocParser;
    private readonly PhpDocTypeConverter $phpDoc;
    private readonly NativeTypeConverter $native;
    private readonly NameScopeFactory $scopes;

    public function __construct()
    {
        $config = new ParserConfig([]);
        $constExprParser = new ConstExprParser($config);
        $this->lexer = new Lexer($config);
        $this->phpDocParser = new PhpDocParser($config, new TypeParser($config, $constExprParser), $constExprParser);
        $this->phpDoc = new PhpDocTypeConverter();
        $this->native = new NativeTypeConverter($this->phpDoc);
        $this->scopes = new NameScopeFactory();
    }

    public function resolve(\ReflectionFunctionAbstract $function): ResolvedSignature
    {
        $class = self::declaringClass($function);
        $subject = ($class !== null && $function instanceof \ReflectionMethod ? $class . '::' : '')
            . $function->getName() . '()';
        $errors = new PreRunErrors();

        $doc = $this->parseDoc($function->getDocComment());
        $docClass = $function instanceof \ReflectionMethod ? self::docClass($function) : ($class === null ? null : new \ReflectionClass($class));
        $classDoc = $docClass === null ? null : $this->parseDoc($docClass->getDocComment());
        $scope = $this->scopes->forFunction(
            $function,
            $class,
            $classDoc === null ? [] : self::aliases($classDoc),
            [...self::templates($doc), ...($classDoc === null ? [] : self::templates($classDoc))],
        );

        $paramTags = $this->winningParamTags($doc, $function, $subject, $errors);
        $parameters = [];
        foreach ($function->getParameters() as $parameter) {
            $name = $parameter->getName();
            $parameters[$name] = $this->resolveParameter($parameter, $paramTags[$name] ?? null, $scope, "{$subject} parameter \${$name}", $errors);
        }

        $returnTag = $this->winningTag($doc, self::RETURN_TAGS, $subject, $errors);
        $return = $this->resolveReturn($function, $returnTag, $scope, "{$subject} return type", $errors);
        $throws = $this->resolveThrows($doc, $scope, "{$subject} @throws", $errors);

        if ($function instanceof \ReflectionMethod) {
            $this->checkInheritance($function, array_keys($paramTags), $returnTag !== null, self::throwsTags($doc) !== [], $subject, $errors);
        }

        return new ResolvedSignature($subject, $parameters, $return, $throws, $errors->all());
    }

    /**
     * @param ?array{string, TypeNode} $tag
     */
    private function resolveParameter(
        \ReflectionParameter $parameter,
        ?array $tag,
        NameScope $scope,
        string $subject,
        PreRunErrors $errors,
    ): ?Type {
        if ($parameter->isVariadic()) {
            $errors->add($subject, 'variadic parameters are not supported in Stage 1');

            return null;
        }

        [$native, $docType, $ok] = $this->sides($parameter->getType(), $tag, $scope, $subject, $errors);
        if (!$ok) {
            return null;
        }

        if ($native === null && $docType === null) {
            $errors->add($subject, 'no native or PHPDoc type; mixed cannot be generated (ADR-0010 section 1)');

            return null;
        }

        $type = $docType ?? $native;
        $validated = $type;
        if (!self::reportInputProblems($type, $subject, $errors)) {
            return null;
        }

        if ($native !== null && $docType !== null) {
            $narrowing = Subtype::check($docType, $native);
            if ($narrowing !== Certainty::Yes) {
                $errors->add($subject, self::narrowingMessage($docType, $native, $narrowing));

                return null;
            }
            $type = self::keepDisjointNativeMembers($docType, $native);
        }

        // As PHPStan does, a PHPDoc type of a parameter that defaults to null also accepts null.
        if ($docType !== null && str_ends_with((string) $parameter, '= NULL ]')
            && Subtype::check(new NullType(), $type) !== Certainty::Yes) {
            $type = UnionType::of($type, new NullType());
        }

        return $type === $validated || self::reportInputProblems($type, $subject, $errors) ? $type : null;
    }

    private static function reportInputProblems(Type $type, string $subject, PreRunErrors $errors): bool
    {
        $problems = InputPosition::problems($type);
        foreach ($problems as $problem) {
            $errors->add($subject, $problem);
        }

        return $problems === [];
    }

    /**
     * PHPStan's rule for a PHPDoc type over a native union (`TypehintHelper::decideType`): native
     * members the PHPDoc type does not overlap stay in the resolved type, so `@param positive-int`
     * on `?int` is `int<1, max>|null`. Called only once the PHPDoc type is known to narrow `$native`.
     */
    private static function keepDisjointNativeMembers(Type $docType, Type $native): Type
    {
        if (!$native instanceof UnionType) {
            return $docType;
        }

        $atoms = $docType instanceof UnionType ? $docType->members : [$docType];
        $kept = [];
        foreach ($native->members as $member) {
            $overlaps = false;
            foreach ($atoms as $atom) {
                if (Subtype::check($atom, $member) !== Certainty::No) {
                    $overlaps = true;
                    break;
                }
            }
            if (!$overlaps) {
                $kept[] = $member;
            }
        }

        return $kept === [] ? $docType : UnionType::of($docType, ...$kept);
    }

    /**
     * @param ?array{string, TypeNode} $tag
     */
    private function resolveReturn(
        \ReflectionFunctionAbstract $function,
        ?array $tag,
        NameScope $scope,
        string $subject,
        PreRunErrors $errors,
    ): ?Type {
        [$native, $docType, $ok] = $this->sides($function->getReturnType(), $tag, $scope, $subject, $errors);
        if (!$ok) {
            return null;
        }

        if ($native !== null && $docType !== null) {
            $narrowing = Subtype::check($docType, $native);
            if ($narrowing === Certainty::No) {
                $errors->add($subject, self::narrowingMessage($docType, $native, $narrowing));

                return null;
            }
            if ($narrowing === Certainty::Maybe) {
                return new UncheckedType(PhpDocTypeConverter::written($tag[1]), self::narrowingMessage($docType, $native, $narrowing));
            }

            return self::keepDisjointNativeMembers($docType, $native);
        }

        return $docType ?? $native ?? new MixedType();
    }

    /**
     * Converts the native type and the winning PHPDoc type; `$ok` is false when either failed with
     * a pre-run error.
     *
     * @param ?array{string, TypeNode} $tag
     * @return array{?Type, ?Type, bool}
     */
    private function sides(?\ReflectionType $nativeType, ?array $tag, NameScope $scope, string $subject, PreRunErrors $errors): array
    {
        $nativeMessages = [];
        $native = $nativeType === null ? null : $this->native->convert($nativeType, $scope->class, $nativeMessages);
        $nativeFailed = $nativeType !== null && $native === null;
        foreach ($nativeMessages as $message) {
            $errors->add($subject, "native type {$nativeType}: {$message}");
        }

        $docMessages = [];
        $docType = $tag === null ? null : $this->phpDoc->convert($tag[1], $scope, $docMessages);
        $docFailed = $tag !== null && $docType === null;
        foreach ($docMessages as $message) {
            $errors->add($subject, $tag[0] . ' ' . PhpDocTypeConverter::written($tag[1]) . ": {$message}");
        }

        return [$native, $docType, !$nativeFailed && !$docFailed];
    }

    private static function narrowingMessage(Type $docType, Type $native, Certainty $answer): string
    {
        return $answer === Certainty::No
            ? "PHPDoc type {$docType->describe()} is not a subtype of native type {$native->describe()}"
            : "cannot check that PHPDoc type {$docType->describe()} narrows native type {$native->describe()}: "
                . 'a fragment is outside the Stage 1 list';
    }

    /**
     * The winning `@param`-family tag per parameter name. A `@psalm-*` tag that does not parse is
     * skipped and the previous tag applies; any other tag that does not parse is a pre-run error.
     *
     * @return array<string, array{string, TypeNode}>
     */
    private function winningParamTags(PhpDocNode $doc, \ReflectionFunctionAbstract $function, string $subject, PreRunErrors $errors): array
    {
        $names = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $function->getParameters());
        $winners = [];
        foreach (self::PARAM_TAGS as $tagName) {
            foreach ($doc->getTagsByName($tagName) as $tag) {
                if ($tag->value instanceof InvalidTagValueNode) {
                    self::invalidTag($tagName, $tag->value, $subject, $errors);
                    continue;
                }
                if (!$tag->value instanceof ParamTagValueNode) {
                    continue;
                }
                $name = substr($tag->value->parameterName, 1);
                if (!in_array($name, $names, true)) {
                    $errors->add($subject, "{$tagName} names \${$name}, which is not a parameter");
                    continue;
                }
                $winners[$name] = [$tagName, $tag->value->type];
            }
        }

        return $winners;
    }

    /**
     * @param list<string> $tagNames in precedence order
     * @return ?array{string, TypeNode}
     */
    private function winningTag(PhpDocNode $doc, array $tagNames, string $subject, PreRunErrors $errors): ?array
    {
        $winner = null;
        foreach ($tagNames as $tagName) {
            foreach ($doc->getTagsByName($tagName) as $tag) {
                if ($tag->value instanceof InvalidTagValueNode) {
                    self::invalidTag($tagName, $tag->value, $subject, $errors);
                } elseif ($tag->value instanceof ReturnTagValueNode) {
                    $winner = [$tagName, $tag->value->type];
                }
            }
        }

        return $winner;
    }

    private static function invalidTag(string $tagName, InvalidTagValueNode $value, string $subject, PreRunErrors $errors): void
    {
        if (str_starts_with($tagName, '@psalm-')) {
            return;
        }

        $errors->add($subject, "{$tagName} {$value->value} does not parse: {$value->exception?->getMessage()}");
    }

    private function resolveThrows(PhpDocNode $doc, NameScope $scope, string $subject, PreRunErrors $errors): ?ThrowsDeclaration
    {
        $tags = self::throwsTags($doc);
        if ($tags === []) {
            return null;
        }

        $classes = [];
        $void = false;
        $failed = false;
        foreach ($tags as $tag) {
            if ($tag->value instanceof InvalidTagValueNode) {
                self::invalidTag($tag->name, $tag->value, $subject, $errors);
                $failed = true;
                continue;
            }
            if (!$tag->value instanceof ThrowsTagValueNode) {
                continue;
            }
            $nodes = $tag->value->type instanceof UnionTypeNode ? $tag->value->type->types : [$tag->value->type];
            foreach ($nodes as $node) {
                if ($node instanceof IdentifierTypeNode && strtolower($node->name) === 'void') {
                    $void = true;
                    continue;
                }
                if (!$node instanceof IdentifierTypeNode) {
                    $errors->add($subject, "{$node} is not a class name or void");
                    $failed = true;
                    continue;
                }
                $messages = [];
                $name = in_array(strtolower($node->name), ['self', 'static'], true) && $scope->class !== null
                    ? $scope->class
                    : $scope->resolveClassName($node->name);
                $type = $this->phpDoc->className($name, $messages);
                foreach ($messages as $message) {
                    $errors->add($subject, $message);
                }
                if ($type instanceof ClassType && is_a($type->name, \Throwable::class, true)) {
                    $classes[$type->name] = $type->name;
                } else {
                    if ($type !== null) {
                        $errors->add($subject, "{$type->describe()} is not a Throwable");
                    }
                    $failed = true;
                }
            }
        }

        if ($void && $classes !== []) {
            $errors->add($subject, '@throws void is combined with exception classes');
            $failed = true;
        }

        return $failed ? null : new ThrowsDeclaration(array_values($classes));
    }

    /**
     * The `@throws`-family tags PHPStan reads: `@phpstan-throws` when present, otherwise `@throws`
     * (ADR-0009 section 5).
     *
     * @return list<\PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode>
     */
    private static function throwsTags(PhpDocNode $doc): array
    {
        $tags = $doc->getTagsByName('@phpstan-throws');

        return array_values($tags !== [] ? $tags : $doc->getTagsByName('@throws'));
    }

    /**
     * PHPStan inherits a parameter, return or `@throws` PHPDoc from an overridden or implemented
     * method when this method does not declare its own. Stage 1 does not; such an inherited
     * declaration is a pre-run error rather than silently ignored.
     *
     * @param list<string> $taggedParameters
     */
    private function checkInheritance(
        \ReflectionMethod $method,
        array $taggedParameters,
        bool $hasReturnTag,
        bool $hasThrows,
        string $subject,
        PreRunErrors $errors,
    ): void {
        $name = $method->getName();
        $class = $method->getDeclaringClass();
        $ancestors = [];
        for ($parent = $class->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            $ancestors[] = $parent;
        }
        array_push($ancestors, ...array_values($class->getInterfaces()));

        $positions = [];
        foreach ($method->getParameters() as $parameter) {
            $positions[$parameter->getPosition()] = in_array($parameter->getName(), $taggedParameters, true);
        }

        $seen = [];
        foreach ($ancestors as $ancestor) {
            if (!$ancestor->hasMethod($name)) {
                continue;
            }
            $inherited = $ancestor->getMethod($name);
            $key = $inherited->getDeclaringClass()->getName() . '::' . $name;
            if ($inherited->isPrivate() || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $doc = $this->parseDoc($inherited->getDocComment());
            $slots = [];
            $ancestorPositions = [];
            foreach ($inherited->getParameters() as $parameter) {
                $ancestorPositions[$parameter->getName()] = $parameter->getPosition();
            }
            foreach (self::PARAM_TAGS as $tagName) {
                foreach ($doc->getParamTagValues($tagName) as $value) {
                    $position = $ancestorPositions[substr($value->parameterName, 1)] ?? null;
                    if ($position !== null && ($positions[$position] ?? true) === false) {
                        $slots[$position] = "parameter #{$position}";
                    }
                }
            }
            ksort($slots);
            if (!$hasReturnTag) {
                foreach (self::RETURN_TAGS as $tagName) {
                    if ($doc->getReturnTagValues($tagName) !== []) {
                        $slots['return'] = 'return type';
                        break;
                    }
                }
            }
            if (!$hasThrows && self::throwsTags($doc) !== []) {
                $slots['throws'] = '@throws';
            }
            if ($slots !== []) {
                $errors->add(
                    $subject,
                    "the PHPDoc of {$key}() for " . implode(', ', $slots)
                        . ' would be inherited, as PHPStan does; PHPDoc inheritance is not supported in Stage 1',
                );
            }
        }
    }

    /**
     * The class-like whose docblock holds the aliases and templates in scope for a method: the
     * trait that declares it, when it comes from one, otherwise the declaring class.
     */
    private static function docClass(\ReflectionMethod $method): \ReflectionClass
    {
        $class = $method->getDeclaringClass();
        $pending = array_values($class->getTraits());
        while ($pending !== []) {
            $trait = array_shift($pending);
            if ($trait->hasMethod($method->getName())) {
                $candidate = $trait->getMethod($method->getName());
                if ($candidate->getFileName() === $method->getFileName()
                    && $candidate->getStartLine() === $method->getStartLine()) {
                    return $trait;
                }
            }
            array_push($pending, ...array_values($trait->getTraits()));
        }

        return $class;
    }

    private function parseDoc(string|false $doc): PhpDocNode
    {
        if ($doc === false) {
            return new PhpDocNode([]);
        }

        return $this->phpDocParser->parse(new TokenIterator($this->lexer->tokenize($doc)));
    }

    /**
     * @return array<string, string> alias name => declaring tag
     */
    private static function aliases(PhpDocNode $classDoc): array
    {
        $aliases = [];
        foreach (self::ALIAS_TAGS as $tagName) {
            foreach ($classDoc->getTagsByName($tagName) as $tag) {
                if ($tag->value instanceof TypeAliasTagValueNode) {
                    $aliases[$tag->value->alias] = $tagName;
                } elseif ($tag->value instanceof TypeAliasImportTagValueNode) {
                    $aliases[$tag->value->importedAs ?? $tag->value->importedAlias] = $tagName;
                }
            }
        }

        return $aliases;
    }

    /**
     * @return list<string>
     */
    private static function templates(PhpDocNode $doc): array
    {
        $names = [];
        foreach ($doc->getTags() as $tag) {
            if ($tag->value instanceof TemplateTagValueNode) {
                $names[] = $tag->value->name;
            }
        }

        return $names;
    }

    /**
     * @return ?class-string
     */
    private static function declaringClass(\ReflectionFunctionAbstract $function): ?string
    {
        if ($function instanceof \ReflectionMethod) {
            return $function->getDeclaringClass()->getName();
        }

        return $function->getClosureScopeClass()?->getName();
    }
}
