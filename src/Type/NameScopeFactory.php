<?php

declare(strict_types=1);

namespace Pront\Type;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Reads the namespace and class `use` imports in effect at a function's declaration from its
 * source file (reflection does not expose imports). Parsed files are cached per instance.
 */
final class NameScopeFactory
{
    private readonly Parser $parser;

    /** @var array<string, list<Stmt>> */
    private array $files = [];

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForHostVersion();
    }

    /**
     * @param ?class-string $class
     * @param array<string, string> $aliases
     * @param list<string> $templates
     */
    public function forFunction(
        \ReflectionFunctionAbstract $function,
        ?string $class,
        array $aliases,
        array $templates,
    ): NameScope {
        $file = $function->getFileName();
        $line = $function->getStartLine();
        if ($file === false || $line === false || !is_file($file)) {
            // Internal or eval()'d code: no source to read imports from.
            $namespace = $function instanceof \ReflectionMethod
                ? $function->getDeclaringClass()->getNamespaceName()
                : $function->getNamespaceName();

            return new NameScope($namespace, [], $class, $aliases, $templates);
        }

        $stmts = $this->parse($file);
        $namespace = '';
        $uses = self::uses($stmts, $line);
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_ && $stmt->getStartLine() <= $line && $line <= $stmt->getEndLine()) {
                $namespace = $stmt->name?->toString() ?? '';
                $uses = self::uses($stmt->stmts, $line);
            }
        }

        return new NameScope($namespace, $uses, $class, $aliases, $templates);
    }

    /**
     * @param array<Node> $stmts
     * @return array<string, string>
     */
    private static function uses(array $stmts, int $beforeLine): array
    {
        $uses = [];
        foreach ($stmts as $stmt) {
            if ($stmt->getStartLine() > $beforeLine) {
                break;
            }
            if ($stmt instanceof Stmt\Use_) {
                foreach ($stmt->uses as $use) {
                    if ($stmt->type === Stmt\Use_::TYPE_NORMAL) {
                        $uses[strtolower($use->getAlias()->toString())] = $use->name->toString();
                    }
                }
            } elseif ($stmt instanceof Stmt\GroupUse) {
                foreach ($stmt->uses as $use) {
                    $type = $stmt->type !== Stmt\Use_::TYPE_UNKNOWN ? $stmt->type : $use->type;
                    if ($type === Stmt\Use_::TYPE_NORMAL) {
                        $uses[strtolower($use->getAlias()->toString())] =
                            $stmt->prefix->toString() . '\\' . $use->name->toString();
                    }
                }
            }
        }

        return $uses;
    }

    /**
     * @return list<Stmt>
     */
    private function parse(string $file): array
    {
        if (!isset($this->files[$file])) {
            $code = file_get_contents($file);
            $this->files[$file] = $code === false ? [] : array_values($this->parser->parse($code) ?? []);
        }

        return $this->files[$file];
    }
}
