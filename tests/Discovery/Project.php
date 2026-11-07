<?php

declare(strict_types=1);

namespace Pront\Tests\Discovery;

/**
 * Writes a throwaway project directory for one discovery case. `{NS}` in a file's code is replaced
 * with a namespace unique to the project, so classes of different cases never collide in one
 * PHPUnit process.
 */
final class Project
{
    private static int $counter = 0;

    private static ?string $root = null;

    public readonly string $directory;

    public readonly string $namespace;

    /**
     * @param array<string, string> $files relative path => code
     */
    public function __construct(array $files)
    {
        $id = ++self::$counter;
        $this->namespace = "Pront\\Tests\\Discovery\\Generated\\P{$id}";
        $this->directory = self::root() . "/P{$id}";
        foreach ($files as $path => $code) {
            $file = "{$this->directory}/{$path}";
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0o700, true);
            }
            file_put_contents($file, str_replace('{NS}', $this->namespace, $code));
        }
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o700, true);
        }
    }

    /**
     * Strips this project's namespace and directory, so expectations stay readable.
     */
    public function relative(string $text): string
    {
        return str_replace(
            [$this->namespace . '\\', (string) realpath($this->directory) . '/'],
            ['', ''],
            $text,
        );
    }

    private static function root(): string
    {
        if (self::$root === null) {
            self::$root = sys_get_temp_dir() . '/pront-discovery-tests-' . getmypid();
            mkdir(self::$root, 0o700, true);
            $root = self::$root;
            register_shutdown_function(static function () use ($root): void {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($iterator as $entry) {
                    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
                }
                rmdir($root);
            });
        }

        return self::$root;
    }
}
