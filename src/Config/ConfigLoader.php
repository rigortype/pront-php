<?php

declare(strict_types=1);

namespace Pront\Config;

/**
 * Evaluates `pront.php`. It is `require`d, not `require_once`d, so it is evaluated again wherever
 * trials run (ADR-0004); in Stage 1 that is the same process.
 */
final class ConfigLoader
{
    public const string FILE_NAME = 'pront.php';

    /**
     * @throws InvalidConfiguration when the file is missing, throws, or returns no Config
     */
    public static function load(string $file): Config
    {
        if (!is_file($file)) {
            throw new InvalidConfiguration("{$file} does not exist");
        }

        try {
            $config = (static fn(string $__file): mixed => require $__file)($file);
        } catch (\Throwable $e) {
            throw new InvalidConfiguration("{$file}: " . $e::class . ": {$e->getMessage()}", previous: $e);
        }
        if (!$config instanceof Config) {
            throw new InvalidConfiguration(sprintf(
                '%s must return a %s, %s returned',
                $file,
                Config::class,
                get_debug_type($config),
            ));
        }

        return $config;
    }
}
