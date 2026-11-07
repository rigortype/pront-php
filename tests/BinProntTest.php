<?php

declare(strict_types=1);

namespace Pront\Tests;

use PHPUnit\Framework\TestCase;
use Pront\Version;

final class BinProntTest extends TestCase
{
    public function testStubPrintsUsageAndExitsWithTwo(): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/pront'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(2, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Pront ' . Version::ID . "\n", (string) $stderr);
        self::assertStringContainsString('Usage: vendor/bin/pront', (string) $stderr);
        self::assertStringContainsString('nothing was verified', (string) $stderr);
    }
}
