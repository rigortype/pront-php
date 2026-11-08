<?php

declare(strict_types=1);

namespace Pront\Tests\Type;

/**
 * Writes a target method into its own file and namespace and loads it, so each case is resolved
 * through real reflection and real `use` imports.
 */
final class TargetFactory
{
    private const string PRELUDE = <<<'PHP'
        use Pront\Tests\Type\Fixtures\Suit;
        use Pront\Tests\Type\Fixtures\{BackedSuit, Shape as Figure};
        use Pront\Tests\Type\Fixtures\Circle;
        use Pront\Tests\Type\Fixtures\Overflow;
        use Pront\Tests\Type\Fixtures as Fx;
        use function strlen;
        PHP;

    private static int $counter = 0;

    /**
     * @param string $method the method declaration, docblock included, for example
     *     "/** @param int<0, 5> \$x *\/ public function m(int \$x): void {}"
     * @param string $classDoc the docblock of the declaring class
     * @param string $declaration the declaration header, for example "enum Target" or
     *     "class Target extends \\Exception"
     * @param string $after code after the declaration
     */
    public static function method(
        string $method,
        string $classDoc = '',
        string $declaration = 'class Target',
        string $after = '',
    ): \ReflectionMethod {
        $namespace = 'Pront\\Tests\\Type\\Generated\\N' . ++self::$counter;
        $prelude = self::PRELUDE;
        $code = <<<PHP
            <?php

            namespace {$namespace};

            {$prelude}

            {$classDoc}
            {$declaration}
            {
                {$method}
            }

            {$after}
            PHP;

        $dir = sys_get_temp_dir() . '/pront-type-tests-' . getmypid();
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
            // The files must outlive the test that resolves them (imports are read from source).
            register_shutdown_function(static function () use ($dir): void {
                array_map('unlink', glob("{$dir}/*.php") ?: []);
                rmdir($dir);
            });
        }
        $file = "{$dir}/N" . self::$counter . '.php';
        file_put_contents($file, $code);
        require $file;

        return new \ReflectionMethod($namespace . '\\Target', 'm');
    }
}
