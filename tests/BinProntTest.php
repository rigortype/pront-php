<?php

declare(strict_types=1);

namespace Pront\Tests;

use PHPUnit\Framework\TestCase;
use Pront\Version;

final class BinProntTest extends TestCase
{
    private const string BIN = __DIR__ . '/../bin/pront';

    public function testStubPrintsUsageAndExitsWithTwo(): void
    {
        [$exitCode, $stdout, $stderr] = self::runBin([PHP_BINARY, self::BIN]);

        self::assertSame(2, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Pront ' . Version::ID . "\n", $stderr);
        self::assertStringContainsString('Usage: vendor/bin/pront', $stderr);
        self::assertStringContainsString('nothing was verified', $stderr);
    }

    /**
     * Installed as a dependency, bin/pront runs through a Composer bin proxy, which sets
     * $_composer_autoload_path and includes the script instead of executing it.
     */
    public function testStubLoadsTheAutoloaderTheComposerProxyGives(): void
    {
        [$exitCode, $stdout, $stderr] = self::runBin([
            PHP_BINARY,
            '-r',
            '$GLOBALS["_composer_autoload_path"] = $argv[1]; include $argv[2];',
            '--',
            __DIR__ . '/Fixtures/proxy-autoload.php',
            self::BIN,
        ]);

        self::assertSame(2, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringStartsWith("proxy autoloader loaded\nPront " . Version::ID . "\n", $stderr);
    }

    /**
     * Output goes to temporary files rather than pipes, so a child that writes more than a pipe
     * buffer to either stream cannot deadlock the test.
     *
     * @param list<string> $command
     * @return array{int, string, string}
     */
    private static function runBin(array $command): array
    {
        $stdoutFile = tmpfile();
        $stderrFile = tmpfile();
        self::assertIsResource($stdoutFile);
        self::assertIsResource($stderrFile);
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $stdoutFile, 2 => $stderrFile], $pipes);
        self::assertIsResource($process);
        $exitCode = proc_close($process);

        $read = static function ($file): string {
            rewind($file);
            return (string) stream_get_contents($file);
        };

        return [$exitCode, $read($stdoutFile), $read($stderrFile)];
    }
}
