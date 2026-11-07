<?php

declare(strict_types=1);

namespace Pront\Discovery;

use Pront\Config\Config;
use Pront\PreRun\PreRunErrors;

/**
 * The files discovery reads: every `*.php` file under the configured `paths` (an explicitly named
 * file is taken whatever its extension), minus the `exclude` patterns, sorted and without
 * duplicates. A path that does not exist, or whose every file is excluded, is a pre-run error.
 * `vendor` is excluded unless a path names something inside it.
 */
final class SourceFiles
{
    /**
     * @return list<string> real paths
     */
    public static function collect(Config $config, string $baseDirectory, PreRunErrors $errors): array
    {
        $base = realpath($baseDirectory);
        if ($base === false) {
            $errors->add('pront.php', "the base directory {$baseDirectory} does not exist");

            return [];
        }
        $defaults = array_map(self::regex(...), Config::DEFAULT_EXCLUDE);
        $patterns = array_map(self::regex(...), $config->exclude);

        $kept = [];
        foreach ($config->paths as $path) {
            $absolute = self::isAbsolute($path) ? $path : $base . DIRECTORY_SEPARATOR . $path;
            $real = realpath($absolute);
            if ($real === false) {
                $errors->add('pront.php', "paths: {$path} does not exist");
                continue;
            }
            $files = [];
            if (is_file($real)) {
                $files[] = $real;
            } else {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
                );
                foreach ($iterator as $file) {
                    if ($file instanceof \SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'php') {
                        $files[] = (string) realpath($file->getPathname());
                    }
                }
            }

            // A path the user names inside `vendor` overrides the default exclusion.
            $applied = self::excluded($real, $base, $defaults) ? $patterns : [...$defaults, ...$patterns];
            $remaining = array_filter($files, static fn(string $file): bool => !self::excluded($file, $base, $applied));
            if ($files !== [] && $remaining === []) {
                $errors->add('pront.php', "paths: every file under {$path} is excluded");
            }
            array_push($kept, ...$remaining);
        }

        $kept = array_values(array_unique($kept));
        sort($kept, SORT_STRING);

        return $kept;
    }

    /**
     * @param list<string> $patterns
     */
    private static function excluded(string $file, string $base, array $patterns): bool
    {
        $relative = str_starts_with($file, $base . DIRECTORY_SEPARATOR)
            ? substr($file, strlen($base) + 1)
            : $file;
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        // The file itself and each of its parent directories.
        $candidates = [];
        for ($path = $relative; $path !== '' && $path !== '.' && $path !== '/'; $path = dirname($path)) {
            $candidates[] = $path;
            if (!str_contains($path, '/')) {
                break;
            }
        }
        foreach ($patterns as $pattern) {
            foreach ($candidates as $candidate) {
                if (preg_match($pattern, $candidate) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function regex(string $pattern): string
    {
        $pattern = str_replace('\\', '/', $pattern);
        while (str_starts_with($pattern, './')) {
            $pattern = substr($pattern, 2);
        }
        $pattern = trim($pattern, '/');
        $regex = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '*' && ($pattern[$i + 1] ?? '') === '*' && ($pattern[$i + 2] ?? '') === '/') {
                // `**/` also matches no directory at all: `**/Fixtures` matches `Fixtures`.
                $regex .= '(?:.*/)?';
                $i += 2;
            } elseif ($char === '*' && ($pattern[$i + 1] ?? '') === '*') {
                $regex .= '.*';
                $i++;
            } elseif ($char === '*') {
                $regex .= '[^/]*';
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($char, '#');
            }
        }

        return '#\A' . $regex . '\z#';
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('#\A[A-Za-z]:[/\\\\]#', $path) === 1;
    }
}
