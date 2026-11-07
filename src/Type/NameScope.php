<?php

declare(strict_types=1);

namespace Pront\Type;

/**
 * What a PHPDoc name means where it is written: the declaring file's namespace and class `use`
 * imports (ADR-0003 item 3), the declaring class for `self` and `static`, and the names that are
 * not classes there (type aliases and template types, which Stage 1 does not support).
 */
final readonly class NameScope
{
    /**
     * @param array<string, string> $uses lower-cased alias => fully qualified name
     * @param ?class-string $class
     * @param array<string, string> $aliases alias name => the tag that declares it
     * @param list<string> $templates
     */
    public function __construct(
        public string $namespace = '',
        public array $uses = [],
        public ?string $class = null,
        public array $aliases = [],
        public array $templates = [],
    ) {
    }

    /**
     * The fully qualified name (no leading backslash) a class name written in PHPDoc stands for,
     * by PHP's rules: `\A` is fully qualified, `namespace\A` is relative to the current namespace,
     * an imported first segment is replaced, anything else is prefixed with the namespace.
     */
    public function resolveClassName(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        if (strncasecmp($name, 'namespace\\', 10) === 0) {
            return $this->qualify(substr($name, 10));
        }

        $parts = explode('\\', $name, 2);
        $imported = $this->uses[strtolower($parts[0])] ?? null;
        if ($imported !== null) {
            return isset($parts[1]) ? $imported . '\\' . $parts[1] : $imported;
        }

        return $this->qualify($name);
    }

    private function qualify(string $name): string
    {
        return $this->namespace === '' ? $name : $this->namespace . '\\' . $name;
    }
}
