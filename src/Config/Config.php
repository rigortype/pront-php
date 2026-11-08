<?php

declare(strict_types=1);

namespace Pront\Config;

/**
 * What `pront.php` returns (ADR-0013 section 2), limited to Stage 1's `paths` and `exclude`
 * (ADR-0014 section 3).
 *
 * `$paths` are files or directories to discover in; `$exclude` are patterns matched against a
 * path relative to the directory of `pront.php`, with `/` as separator: `*` and `?` stay inside
 * one path segment, `**` crosses segments, and `**` before a slash also matches no directory at
 * all. A pattern excludes a file when it matches the file or one of its parent directories, so
 * `tests` and `tests/**` both exclude everything under `tests/`.
 * `vendor` is excluded by default (ADR-0012 section 1), unless a path names something inside it.
 */
final readonly class Config
{
    public const array DEFAULT_EXCLUDE = ['vendor'];

    /** @var list<string> */
    public array $paths;

    /** @var list<string> */
    public array $exclude;

    /**
     * @param list<string> $paths
     * @param list<string> $exclude
     */
    public function __construct(array $paths, array $exclude = [])
    {
        if ($paths === []) {
            throw new \InvalidArgumentException('paths must name at least one file or directory');
        }
        $this->paths = self::strings($paths, 'paths');
        $this->exclude = self::strings($exclude, 'exclude');
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private static function strings(array $values, string $name): array
    {
        if (!array_is_list($values)) {
            throw new \InvalidArgumentException("{$name} must be a list");
        }
        foreach ($values as $i => $value) {
            if (!is_string($value) || $value === '') {
                throw new \InvalidArgumentException("{$name}[{$i}] must be a non-empty string");
            }
        }

        /** @var list<string> $values */
        return $values;
    }
}
