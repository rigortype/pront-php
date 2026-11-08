<?php

declare(strict_types=1);

namespace Pront\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pront\Config\Config;
use Pront\Config\ConfigLoader;
use Pront\Config\InvalidConfiguration;
use Pront\Tests\Discovery\Project;

final class ConfigTest extends TestCase
{
    public function testFields(): void
    {
        $config = new Config(['src', 'lib'], ['tests/**']);

        self::assertSame(['src', 'lib'], $config->paths);
        self::assertSame(['tests/**'], $config->exclude);
        self::assertSame([], (new Config(['src']))->exclude);
    }

    /**
     * @return iterable<string, array{array<mixed>, array<mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'no paths' => [[], [], 'paths must name at least one'];
        yield 'empty path' => [[''], [], 'paths[0] must be a non-empty string'];
        yield 'non-string path' => [['src', 3], [], 'paths[1] must be a non-empty string'];
        yield 'keyed paths' => [['a' => 'src'], [], 'paths must be a list'];
        yield 'empty pattern' => [['src'], [''], 'exclude[0] must be a non-empty string'];
    }

    /**
     * @param array<mixed> $paths
     * @param array<mixed> $exclude
     */
    #[DataProvider('invalid')]
    public function testInvalid(array $paths, array $exclude, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new Config($paths, $exclude);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function files(): iterable
    {
        yield 'a config' => ["<?php return new Pront\\Config\\Config(['src'], ['tests']);", null];
        yield 'no config returned' => ['<?php return ["paths" => ["src"]];', 'must return a Pront\Config\Config, array returned'];
        yield 'nothing returned' => ['<?php', 'must return a Pront\Config\Config, int returned'];
        yield 'throws' => ['<?php throw new RuntimeException("bad");', 'RuntimeException: bad'];
        yield 'invalid config' => ['<?php return new Pront\Config\Config([]);', 'InvalidArgumentException: paths must name at least one'];
    }

    #[DataProvider('files')]
    public function testLoad(string $code, ?string $error): void
    {
        $project = new Project([ConfigLoader::FILE_NAME => $code]);
        $file = $project->directory . '/' . ConfigLoader::FILE_NAME;
        if ($error !== null) {
            $this->expectException(InvalidConfiguration::class);
            $this->expectExceptionMessage($error);
        }

        $config = ConfigLoader::load($file);

        self::assertSame(['src'], $config->paths);
        self::assertSame(['tests'], $config->exclude);
    }

    public function testLoadIsReevaluated(): void
    {
        $project = new Project([ConfigLoader::FILE_NAME => '<?php $GLOBALS["pront_config_loads"] = ($GLOBALS["pront_config_loads"] ?? 0) + 1; return new Pront\Config\Config(["src"]);']);
        $file = $project->directory . '/' . ConfigLoader::FILE_NAME;

        ConfigLoader::load($file);
        ConfigLoader::load($file);

        self::assertSame(2, $GLOBALS['pront_config_loads']);
        unset($GLOBALS['pront_config_loads']);
    }

    public function testMissingFile(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('does not exist');
        ConfigLoader::load(sys_get_temp_dir() . '/pront-no-such-dir/pront.php');
    }
}
