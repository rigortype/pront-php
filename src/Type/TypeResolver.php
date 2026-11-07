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

    /** @var array<string, ResolvedSignature> methods resolved so far, by `Class::method` */
    private array $resolvedMethods = [];

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
        if ($function instanceof \ReflectionMethod) {
            return $this->resolvedMethods[$function->getDeclaringClass()->getName() . '::' . $function->getName()]
                ??= $this->resolveUncached($function);
        }

        return $this->resolveUncached($function);
    }

    private function resolveUncached(\ReflectionFunctionAbstract $function): ResolvedSignature
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

        $own = new ResolvedSignature($subject, $parameters, $return, $throws, $errors->all());
        if ($function instanceof \ReflectionMethod && !$function->getDeclaringClass()->isTrait()) {
            $this->checkInheritance($function, array_keys($paramTags), $returnTag !== null, self::throwsTags($doc) !== [], $own, $errors);
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
            [$type, $undecided] = self::keepDisjointNativeMembers($docType, $native);
            if ($undecided !== null) {
                $errors->add($subject, $undecided);

                return null;
            }
        }

        // As PHPStan does, a PHPDoc type of a parameter that defaults to null also accepts null.
        if ($docType !== null && Subtype::check(new NullType(), $type) !== Certainty::Yes) {
            $default = self::defaultIsNull($parameter);
            if (is_string($default)) {
                $errors->add($subject, $default);

                return null;
            }
            if ($default) {
                $type = UnionType::of($type, new NullType());
            }
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
    /**
     * @return array{Type, ?string} the resolved type, and why it is undecidable when a native
     *     member's disjointness from the PHPDoc type cannot be decided
     */
    private static function keepDisjointNativeMembers(Type $docType, Type $native): array
    {
        if (!$native instanceof UnionType) {
            return [$docType, null];
        }

        $kept = [];
        foreach ($native->members as $member) {
            $disjoint = Disjoint::check($docType, $member);
            if ($disjoint === Certainty::Maybe) {
                return [$docType, "cannot decide whether native type member {$member->describe()} overlaps "
                    . "PHPDoc type {$docType->describe()}: a fragment is outside the Stage 1 list"];
            }
            if ($disjoint === Certainty::Yes) {
                $kept[] = $member;
            }
        }

        return [$kept === [] ? $docType : UnionType::of($docType, ...$kept), null];
    }

    /**
     * Whether the parameter's default value is null, read without running code: the literal
     * `null`, or a constant expression evaluated by reflection. An expression with `new` is not
     * evaluated (it would run a constructor); unless it starts with `new` (an object), it is
     * reported, as is an expression that fails to evaluate.
     */
    private static function defaultIsNull(\ReflectionParameter $parameter): bool|string
    {
        if (!$parameter->isDefaultValueAvailable()) {
            return false;
        }

        $expression = preg_match('/ = (.*) \]\z/s', (string) $parameter, $match) === 1 ? $match[1] : '';
        if (strcasecmp($expression, 'NULL') === 0) {
            return true;
        }
        $tokens = array_values(array_filter(
            token_get_all('<?php ' . $expression),
            static fn(array|string $token): bool => !is_array($token) || !in_array($token[0], [T_OPEN_TAG, T_WHITESPACE], true),
        ));
        $news = array_keys(array_filter($tokens, static fn(array|string $token): bool => is_array($token) && $token[0] === T_NEW));
        if ($news !== []) {
            return $news === [0]
                ? false
                : "cannot tell whether the default value {$expression} is null without running code";
        }

        try {
            return $parameter->getDefaultValue() === null;
        } catch (\Throwable $e) {
            return "the default value {$expression} cannot be evaluated: {$e->getMessage()}";
        }
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

            [$type, $undecided] = self::keepDisjointNativeMembers($docType, $native);

            return $undecided === null ? $type : new UncheckedType(PhpDocTypeConverter::written($tag[1]), $undecided);
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
                $messages = [];
                $type = $node instanceof IdentifierTypeNode ? $this->phpDoc->convert($node, $scope, $messages) : null;
                foreach ($messages as $message) {
                    $errors->add($subject, $message);
                }
                $class = $type === null ? null : Subtype::className($type);
                if ($class !== null && is_a($class, \Throwable::class, true)) {
                    $classes[$class] = $class;
                    continue;
                }
                $failed = true;
                if ($messages === []) {
                    $errors->add($subject, $class === null
                        ? PhpDocTypeConverter::written($node) . ' is not a class name or void'
                        : "{$class} is not a Throwable");
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
     * PHPStan inherits a parameter, return or `@throws` PHPDoc from an overridden, implemented or
     * abstract trait method when this method does not declare its own (parameters by position,
     * constructor parameters by name). Stage 1 does not inherit: when an inherited declaration
     * would change a resolved type, it is a pre-run error rather than silently ignored. An
     * ancestor PHPDoc that only restates what this method already resolves to changes nothing.
     *
     * @param list<string> $taggedParameters
     */
    private function checkInheritance(
        \ReflectionMethod $method,
        array $taggedParameters,
        bool $hasReturnTag,
        bool $hasThrows,
        ResolvedSignature $own,
        PreRunErrors $errors,
    ): void {
        $name = $method->getName();
        $byName = strtolower($name) === '__construct';
        $seen = [];
        foreach (self::ancestorMethods($method) as $inherited) {
            $key = $inherited->getDeclaringClass()->getName() . '::' . $inherited->getName();
            // A method reached through several classes or traits is one source.
            $identity = $inherited->getFileName() . ':' . $inherited->getStartLine() . ':' . $inherited->getName();
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;

            $theirDoc = $this->parseDoc($inherited->getDocComment());
            $theirTagged = [];
            foreach (self::PARAM_TAGS as $tagName) {
                foreach ($theirDoc->getParamTagValues($tagName) as $value) {
                    $theirTagged[] = substr($value->parameterName, 1);
                }
            }
            $theirReturnTagged = false;
            foreach (self::RETURN_TAGS as $tagName) {
                $theirReturnTagged = $theirReturnTagged || $theirDoc->getReturnTagValues($tagName) !== [];
            }

            $theirs = $this->resolve($inherited);
            $theirParameters = array_values($inherited->getParameters());
            $slots = [];
            foreach ($method->getParameters() as $position => $parameter) {
                if (in_array($parameter->getName(), $taggedParameters, true)) {
                    continue;
                }
                $counterpart = $byName ? $parameter->getName() : ($theirParameters[$position] ?? null)?->getName();
                if ($counterpart === null || !in_array($counterpart, $theirTagged, true)) {
                    continue;
                }
                if (self::narrows($theirs->parameters[$counterpart] ?? null, $own->parameters[$parameter->getName()] ?? null, $inherited, $method)) {
                    $slots[] = "parameter \${$parameter->getName()}";
                }
            }
            if (!$hasReturnTag && $theirReturnTagged && self::narrows($theirs->return, $own->return, $inherited, $method)) {
                $slots[] = 'return type';
            }
            if (!$hasThrows && self::throwsTags($theirDoc) !== []) {
                $slots[] = '@throws';
            }
            if ($slots !== []) {
                $errors->add(
                    $own->subject,
                    "the PHPDoc of {$key}() for " . implode(', ', $slots)
                        . ' would be inherited, as PHPStan does; PHPDoc inheritance is not supported in Stage 1',
                );
            }
        }
    }

    /**
     * Whether an inherited slot type would change this method's: PHPStan applies an inherited
     * PHPDoc type only when it is a subtype of the native type (`TypehintHelper::decideType`), so
     * it matters when it is (or may be) a strict subtype. The ancestor's `static` is late-bound and
     * is compared as this method's class. An unresolvable side is always reported.
     */
    private static function narrows(?Type $theirs, ?Type $ours, \ReflectionMethod $ancestor, \ReflectionMethod $method): bool
    {
        if ($theirs === null || $ours === null) {
            return true;
        }

        $theirs = self::rebaseStatic($theirs, $ancestor->getDeclaringClass()->getName(), $method->getDeclaringClass()->getName());
        $into = Subtype::check($theirs, $ours);

        return $into !== Certainty::No && !($into === Certainty::Yes && Subtype::check($ours, $theirs) === Certainty::Yes);
    }

    /**
     * @param class-string $from
     * @param class-string $to
     */
    private static function rebaseStatic(Type $type, string $from, string $to): Type
    {
        $rebase = static fn(Type $t): Type => self::rebaseStatic($t, $from, $to);

        return match (true) {
            $type instanceof StaticType && $type->class === $from => new StaticType($to),
            $type instanceof UnionType => UnionType::of(...array_map($rebase, $type->members)),
            $type instanceof ListType => new ListType($rebase($type->value), $type->nonEmpty),
            $type instanceof ArrayType => new ArrayType($rebase($type->key), $rebase($type->value)),
            $type instanceof ArrayShapeType => new ArrayShapeType(array_map($rebase, $type->items)),
            default => $type,
        };
    }

    /**
     * The non-private same-named methods of the parent chain, of every interface, and the abstract
     * ones of the used traits.
     *
     * @return list<\ReflectionMethod>
     */
    private static function ancestorMethods(\ReflectionMethod $method): array
    {
        $name = $method->getName();
        $class = $method->getDeclaringClass();
        $candidates = [];
        for ($parent = $class->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            $candidates[] = $parent;
        }
        array_push($candidates, ...array_values($class->getInterfaces()));

        $methods = [];
        foreach ($candidates as $candidate) {
            if ($candidate->hasMethod($name) && !$candidate->getMethod($name)->isPrivate()) {
                $methods[] = $candidate->getMethod($name);
            }
        }
        foreach (self::traitsOf($class) as $trait) {
            if ($trait->hasMethod($name) && $trait->getMethod($name)->isAbstract()) {
                $methods[] = $trait->getMethod($name);
            }
        }

        return $methods;
    }

    /**
     * @return list<\ReflectionClass<object>>
     */
    private static function traitsOf(\ReflectionClass $class): array
    {
        $traits = [];
        $pending = array_values($class->getTraits());
        while ($pending !== []) {
            $trait = array_shift($pending);
            $traits[] = $trait;
            array_push($pending, ...array_values($trait->getTraits()));
        }

        return $traits;
    }

    /**
     * The class-like whose docblock holds the aliases and templates in scope for a method: the
     * trait that declares it (under any alias), when it comes from one, otherwise the declaring
     * class.
     */
    private static function docClass(\ReflectionMethod $method): \ReflectionClass
    {
        foreach (self::traitsOf($method->getDeclaringClass()) as $trait) {
            if ($trait->getFileName() === $method->getFileName()
                && $trait->getStartLine() <= $method->getStartLine()
                && $method->getStartLine() <= $trait->getEndLine()) {
                return $trait;
            }
        }

        return $method->getDeclaringClass();
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
