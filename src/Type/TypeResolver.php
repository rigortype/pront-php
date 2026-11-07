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
        $classDoc = $class === null ? null : $this->parseDoc((new \ReflectionClass($class))->getDocComment());
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

        $return = $this->resolveReturn($function, $this->winningTag($doc, self::RETURN_TAGS, $subject, $errors), $scope, "{$subject} return type", $errors);
        $throws = $this->resolveThrows($doc, $scope, "{$subject} @throws", $errors);

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
        $problems = InputPosition::problems($type);
        foreach ($problems as $problem) {
            $errors->add($subject, $problem);
        }
        if ($problems !== []) {
            return null;
        }

        if ($native !== null && $docType !== null) {
            $narrowing = Subtype::check($docType, $native);
            if ($narrowing !== Certainty::Yes) {
                $errors->add($subject, self::narrowingMessage($docType, $native, $narrowing));

                return null;
            }
        }

        return $type;
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
        $tags = $doc->getTagsByName('@throws');
        if ($tags === []) {
            return null;
        }

        $classes = [];
        $void = false;
        $failed = false;
        foreach ($tags as $tag) {
            if ($tag->value instanceof InvalidTagValueNode) {
                self::invalidTag('@throws', $tag->value, $subject, $errors);
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
                $type = $this->phpDoc->className($scope->resolveClassName($node->name), $messages);
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
