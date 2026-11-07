<?php

declare(strict_types=1);

namespace Pront\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pront\ChoiceBuffer\Draw;
use Pront\ChoiceBuffer\DrawKind;
use Pront\Engine\Engine;
use Pront\Engine\ReplayToken;
use Pront\Tests\Discovery\Project;
use Pront\Version;

/**
 * `bin/pront` as a subprocess against throwaway projects: the report, the exit codes of ADR-0013
 * section 3, replay and crash recovery (ADR-0004).
 */
final class BinProntTest extends TestCase
{
    private const string BIN = __DIR__ . '/../bin/pront';

    private const string CONFIG = "<?php\nreturn new \\Pront\\Config\\Config(['src']);\n";

    private const string ZERO_COUNTS = '0 skipped; 0 unchecked (0 return-type fragments, 0 targets with unchecked exceptions); '
        . '0 reserved-name conflicts; 0 unverified until Stage 3; 0 crashed attempts of earlier runs';

    /**
     * @return iterable<string, array{string, list<string>, int, list<string>}> class body, extra
     *     arguments, exit code, lines or fragments expected in this order (namespace stripped)
     */
    public static function runs(): iterable
    {
        yield 'pass' => [
            '/** @param int<0, 10> $x */ #[Verify] public static function m(int $x): int { return $x; }',
            [],
            0,
            ['PASS A::m (100 trials, 100 passed, 0 discarded, seed ', 'Summary: 1 target: 1 passed, 0 failed, 0 flaky, 0 unverifiable, 0 too many discards; 0 pre-run errors; ' . self::ZERO_COUNTS],
        ];
        yield 'counterexample shrunk to its minimum' => [
            "/**\n * @param int<-10000, 10000> \$x\n * @return int<min, 99>\n */\n#[Verify(seed: 1)] public static function m(int \$x): int { return \$x; }",
            [],
            1,
            [
                'FAIL A::m (fresh trial 1 failed; 1 trial, 0 passed, 0 discarded, seed 1)',
                '  Failure: return type: A::m returned int, declared int<min, 99>',
                '  Counterexample (shrunk in ',
                'a local minimum):',
                "\n    \$x = 100\n",
                '  Re-run 5 times: reproduced 5 times.',
                '  Replay: vendor/bin/pront --replay pront:',
                'Summary: 1 target: 0 passed, 1 failed, 0 flaky, 0 unverifiable, 0 too many discards; 0 pre-run errors; ' . self::ZERO_COUNTS,
            ],
        ];
        yield 'pre-run errors are listed first and do not stop the other targets' => [
            '#[Verify] public static function cb(callable $c): void {} #[Verify] public static function m(bool $b): bool { return $b; }',
            [],
            2,
            ['Pre-run errors (1):', '  - A::cb() parameter $c: callable', 'PASS A::m', '1 passed, 0 failed', '1 pre-run error;'],
        ];
        yield 'a counterexample wins over a pre-run error' => [
            '#[Verify] public static function cb(callable $c): void {} #[Verify] public static function m(bool $b): int { return 1 / 0; }',
            [],
            1,
            ['Pre-run errors (1):', 'FAIL A::m', '0 passed, 1 failed', '1 pre-run error;'],
        ];
        yield 'unverifiable' => [
            "/** @param int<0, 10> \$x */\n#[Verify(seed: 1)] #[\\Pront\\Attribute\\Ensures(static function (int \$return): bool { return true; })]\n"
                . "public static function m(int \$x): int|string { return \$x > 5 ? 's' : \$x; }",
            [],
            2,
            [
                'UNVERIFIABLE A::m (fresh trial ',
                '  Failure: unverifiable: A::m() #[Ensures] 1: TypeError@',
                '  Input (shrunk in ',
                "\n    \$x = 6\n",
                'Summary: 1 target: 0 passed, 0 failed, 0 flaky, 1 unverifiable, 0 too many discards',
            ],
        ];
        yield 'nothing to verify' => [
            'public static function m(): void {}',
            [],
            2,
            ['Nothing to verify: the paths of the configuration contain no #[Verify].', 'Summary: 0 targets: 0 passed'],
        ];
        yield 'too many discards' => [
            'public function __construct() { throw new \InvalidArgumentException("never"); } #[Verify(iterations: 1)] public function m(): void {}',
            [],
            2,
            ['ERROR A::m: too many discards, a generator-configuration error (11 trials, 0 passed, 11 discarded', 'A::__construct() threw', '0 passed, 0 failed, 0 flaky, 0 unverifiable, 1 too many discards'],
        ];
        yield 'flaky' => [
            "private static int \$n = 0;\n/** @param int<0, 100> \$x */\n#[Verify(seed: 2)] public static function m(int \$x): void { if (\$x > 50 && self::\$n++ % 2 === 0) { throw new \\Error('odd'); } }",
            [],
            1,
            ['FLAKY A::m (fresh trial ', 'not reproducible (flaky), still counted as a counterexample.', '0 passed, 0 failed, 1 flaky'],
        ];
        yield 'the receiver, an enum, a string, a float and a bool are shown as drawn' => [
            'public function __construct(public int $base, public ?string $label) {} #[Verify] public function m(Suit $s, string $t, float $f, bool $b): int { return "x"; }',
            [],
            1,
            ["\n    \$self = new \\A(base: 0, label: null)\n    \$s = Suit::Hearts\n    \$t = \"\"\n    \$f = 0.0\n    \$b = false\n"],
        ];
        yield 'a failure while building the receiver says the parameters were not drawn' => [
            'public function __construct(int $base) { intdiv($base, 0); } #[Verify] public function m(int $x): void {}',
            [],
            1,
            ["\n    \$self = new \\A(base: 0)\n    (the attempt stopped while building \$self: the parameters below were not drawn)\n    \$x = 0\n"],
        ];
        yield 'unchecked return-type fragments are listed' => [
            '/** @return callable(int): string */ #[Verify] public static function m(): callable { return static fn(int $i): string => ""; }',
            [],
            0,
            ['Unchecked return-type fragments (ADR-0009) (1):', '  - A::m: return type fragment callable(int): string is unchecked', 'PASS A::m', '1 unchecked (1 return-type fragment, 0 targets with unchecked exceptions)'],
        ];
        yield 'unchecked exceptions are listed' => [
            '#[Verify(iterations: 3)] public static function m(): int { throw new \RuntimeException("no"); }',
            [],
            0,
            ['PASS A::m', 'Unchecked exceptions (ADR-0009 section 5) (1):', '  - A::m: 3 attempts ended in an Exception', '1 unchecked (0 return-type fragments, 1 target with unchecked exceptions)'],
        ];
        yield 'unverified until Stage 3' => [
            '#[Verify] public static function m(): int { return 1; } } final class C extends A {',
            [],
            0,
            ['Unverified until Stage 3 (ADR-0012) (1):', '  - C extends A', 'PASS A::m', '1 unverified until Stage 3'],
        ];
        yield 'reserved-name conflicts' => [
            '#[Verify] #[\Pront\Attribute\Ensures(static function ($return): bool { return true; })] public static function m(int $return): int { return $return; }',
            [],
            0,
            ['Reserved-name conflicts (ADR-0009) (1):', 'binds to the method parameter $return', 'PASS A::m', '1 reserved-name conflict;'],
        ];
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $expected
     */
    #[DataProvider('runs')]
    public function testRun(string $body, array $arguments, int $exitCode, array $expected): void
    {
        $project = self::project($body);
        [$code, $stdout, $stderr] = self::pront($project, ['--seed', '42', ...$arguments]);
        $stdout = $project->relative($stdout);

        self::assertStringStartsWith('Pront ' . Version::ID . " (seed 42, algorithm pront-1)\n", $stdout);
        self::assertContainsInOrder($expected, $stdout);
        self::assertSame('', $stderr);
        self::assertSame($exitCode, $code, $stdout);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function strictItems(): iterable
    {
        yield 'unchecked return-type fragment' => ['/** @return callable(int): string */ #[Verify] public static function m(): callable { return static fn(int $i): string => ""; }'];
        yield 'class unverified until Stage 3' => ['#[Verify] public static function m(): int { return 1; } } final class C extends A {'];
        yield 'reserved-name conflict' => ['#[Verify] #[\Pront\Attribute\Ensures(static function ($return): bool { return true; })] public static function m(int $return): int { return $return; }'];
    }

    #[DataProvider('strictItems')]
    public function testStrictTurnsListedItemsIntoExitTwo(string $body): void
    {
        $project = self::project($body);

        self::assertSame(0, self::pront($project, ['--seed', '1'])[0]);
        [$code, $stdout] = self::pront($project, ['--seed', '1', '--strict']);
        self::assertSame(2, $code, $stdout);
        self::assertStringContainsString('PASS A::m', $project->relative($stdout));
    }

    public function testStrictDoesNotFailOnUncheckedExceptions(): void
    {
        $project = self::project('#[Verify(iterations: 3)] public static function m(): int { throw new \RuntimeException("no"); }');

        self::assertSame(0, self::pront($project, ['--strict'])[0]);
    }

    public function testTheSameSeedGivesTheSameReport(): void
    {
        $project = self::project('/** @param int<0, 1000> $x */ #[Verify] public static function m(int $x): void { if ($x > 10) { throw new \Error(); } } #[Verify] public static function n(string $s): string { return $s; }');

        [$first, $out1] = self::pront($project, ['--seed', '-7']);
        [, $out2] = self::pront($project, ['--seed=-7']);
        [, $out3] = self::pront($project, ['--seed', '8']);

        self::assertSame(1, $first);
        self::assertSame($out1, $out2);
        self::assertStringStartsWith('Pront ' . Version::ID . ' (seed -7, algorithm pront-1)', $out1);
        // The target seeds derive from the run seed.
        self::assertNotSame(self::targetSeeds($out1), self::targetSeeds($out3));
    }

    public function testWithoutSeedARandomSeedIsPrinted(): void
    {
        $project = self::project('#[Verify(iterations: 1)] public static function m(): int { return 1; }');

        [$code, $stdout] = self::pront($project, []);
        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/\APront \S+ \(seed -?\d+, algorithm pront-1\)\n/', $stdout);
    }

    public function testReplayReproducesThePrintedCounterexampleAndSaysWhenItNoLongerFails(): void
    {
        $failing = "/**\n * @param int<-10000, 10000> \$x\n * @return int<min, 99>\n */\n#[Verify(seed: 1)] public static function m(int \$x): int { return \$x; }";
        $project = self::project($failing);
        [, $stdout] = self::pront($project, []);
        $token = self::token($stdout);

        [$code, $replay, $stderr] = self::pront($project, ['--replay', $token]);
        $replay = $project->relative($replay);
        self::assertSame(1, $code, $replay);
        self::assertSame('', $stderr);
        self::assertContainsInOrder([
            'Pront ' . Version::ID . " (replay, seed 1, algorithm pront-1)\n",
            "Replaying A::m: fresh trial 1 of seed 1, recorded buffer of 2 draws\n  Input:\n    \$x = 100\n",
            "FAIL A::m: the replay failed\n  Failure: return type: A::m returned int, declared int<min, 99>\n",
            'Summary: 1 replayed attempt: 0 passed, 1 failed, 0 flaky, 0 unverifiable, 0 too many discards; 0 replay errors; 0 pre-run errors; ' . self::ZERO_COUNTS,
        ], $replay);

        file_put_contents($project->directory . '/src/A.php', str_replace('return $x;', 'return min($x, 99);', (string) file_get_contents($project->directory . '/src/A.php')));
        [$code, $fixed] = self::pront($project, ['--replay', $token]);
        $fixed = $project->relative($fixed);
        self::assertSame(0, $code, $fixed);
        self::assertContainsInOrder(['    $x = 100', 'PASS A::m: no longer reproduces', '  ' . Engine::REPLAY_LIMITS, '1 replayed attempt: 1 passed, 0 failed'], $fixed);
    }

    /**
     * @return iterable<string, array{string, string, int, list<string>}> the failing body, the
     *     edited body, exit code, fragments in order
     */
    public static function changedTargets(): iterable
    {
        // Seeds are pinned: which draws a minimal buffer holds depends on the drawn edge selectors.
        $failing = 'public function __construct(int $base) {} #[Verify(seed: 1)] public function m(int $x): void { throw new \Error(); }';
        $readMore = 'The replay read 6 draws of the 4 recorded: the token is a shrink candidate or a stopped attempt, or the parameters of the target changed';
        yield 'a parameter was added' => [
            $failing,
            str_replace('int $x)', 'int $x, int $y)', $failing),
            1,
            ["  Input:\n    \$self = new \\A(base: 0)\n    \$x = 0\n    \$y = 0\nFAIL A::m: the replay failed", '0 passed, 1 failed'],
        ];
        yield 'a parameter was added and the bug fixed' => [
            $failing,
            'public function __construct(int $base) {} #[Verify(seed: 1)] public function m(int $x, int $y): void {}',
            0,
            ['PASS A::m: no longer reproduces', Engine::REPLAY_LIMITS, $readMore, '1 passed, 0 failed', '0 replay errors'],
        ];
        yield 'a parameter changed its type' => [
            $failing,
            'public function __construct(int $base) {} #[Verify(seed: 1)] public function m(bool $x): void {}',
            2,
            ['The recorded draws no longer match the parameters of A::m', 'ERROR A::m: the replay did not fail, but on another input than the recorded one', '1 replay error'],
        ];
        yield 'a narrowed range repairs the recorded value' => [
            '/** @param int<0, 100> $x */ #[Verify(seed: 1)] public static function m(int $x): void { if ($x > 50) { throw new \Error(); } }',
            '/** @param int<0, 10> $x */ #[Verify(seed: 1)] public static function m(int $x): void {}',
            0,
            ["    \$x = 10\n", 'PASS A::m: no longer reproduces', 'The replay read 2 draws of the 2 recorded, repairing some:'],
        ];
        yield 'a fixed constructor, recorded before the parameters were drawn' => [
            'public function __construct(int $base) { intdiv($base, 0); } #[Verify(seed: 1)] public function m(int $x): void {}',
            'public function __construct(int $base) {} #[Verify(seed: 1)] public function m(int $x): void {}',
            0,
            ["    \$self = new \\A(base: 0)\n    (the attempt stopped while building \$self: the parameters below were not drawn)\n    \$x = 0\n", 'PASS A::m: no longer reproduces', 'The replay read 4 draws of the 2 recorded:'],
        ];
        yield 'the input is now discarded' => [
            $failing,
            str_replace('{}', '{ throw new \InvalidArgumentException("gone"); }', $failing),
            0,
            ['PASS A::m: no longer reproduces (the input was discarded: ', 'threw InvalidArgumentException: gone)', Engine::REPLAY_LIMITS, '1 passed'],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('changedTargets')]
    public function testReplayAfterTheTargetChanged(string $failing, string $edited, int $exitCode, array $expected): void
    {
        $project = self::project($failing);
        [$code, $stdout] = self::pront($project, []);
        self::assertSame(1, $code, $stdout);
        $token = self::token($stdout);

        file_put_contents($project->directory . '/src/A.php', str_replace('{NS}', $project->namespace, self::php($edited)));
        [$code, $replay] = self::pront($project, ['--replay', $token]);
        $replay = $project->relative($replay);
        self::assertContainsInOrder($expected, $replay);
        if (!str_contains(implode("\n", $expected), 'not drawn')) {
            self::assertStringNotContainsString('not drawn', $replay);
        }
        self::assertSame($exitCode, $code, $replay);
    }

    /**
     * A crashed shrink candidate is an edited buffer: here one draw longer than the input reads.
     * Replaying it on the unchanged target passes with a caveat, not a replay error.
     */
    public function testReplayOfAShrinkCandidateTokenOnAnUnchangedTarget(): void
    {
        $project = self::project('/** @param int<0, 100> $x */ #[Verify] public static function m(int $x): void {}');
        $token = new ReplayToken($project->namespace . '\\A::m', 1, 1, [
            new Draw(DrawKind::Selector, [0]),
            new Draw(DrawKind::Int, [5, 0]),
            new Draw(DrawKind::Int, [1, 0]),
        ]);

        [$code, $replay] = self::pront($project, ['--replay', $token->toString()]);
        $replay = $project->relative($replay);
        self::assertSame(0, $code, $replay);
        self::assertContainsInOrder(['PASS A::m: no longer reproduces', 'The replay read 2 draws of the 3 recorded:', '0 replay errors'], $replay);
        self::assertStringNotContainsString('no longer match', $replay);
    }

    public function testTheReplayCommandQuotesTheConfigPath(): void
    {
        $project = new Project([
            'with space/pront.php' => self::CONFIG,
            'with space/src/A.php' => self::php('#[Verify] public static function m(): int { return 1 / 0; }'),
        ]);
        $config = $project->directory . '/with space/pront.php';

        [$code, $stdout] = self::runBin([PHP_BINARY, self::BIN, '--config', $config], sys_get_temp_dir());
        self::assertSame(1, $code, $stdout);
        self::assertSame(1, preg_match('/^  Replay: vendor\/bin\/pront (.+)$/m', $stdout, $match), $stdout);
        self::assertStringStartsWith('--config ' . escapeshellarg($config) . ' --replay pront:', $match[1]);

        // The printed arguments survive a shell.
        [$code, $replay] = self::runBin(['/bin/sh', '-c', escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::BIN) . ' ' . $match[1]], sys_get_temp_dir());
        self::assertSame(1, $code, $replay);
        self::assertStringContainsString(': the replay failed', $replay);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function journalEnds(): iterable
    {
        $body = '#[Verify(iterations: 1)] public static function m(): int { return 1; }';
        yield 'a run' => [$body, []];
        yield 'a replay' => [$body, ['--replay', (new ReplayToken('{NS}\\A::m', 1, 1, null))->toString()]];
        yield 'a replay of a target that is not verified' => [$body, ['--replay', (new ReplayToken('Nope::m', 1, 1, null))->toString()]];
        yield 'nothing to verify' => ['public static function m(): void {}', []];
    }

    /**
     * @param list<string> $arguments `{NS}` stands for the project's namespace
     */
    #[DataProvider('journalEnds')]
    public function testARunEndsItsJournal(string $body, array $arguments): void
    {
        $project = self::project($body);
        $arguments = array_map(static function (string $a) use ($project): string {
            if (!str_starts_with($a, ReplayToken::PREFIX) || !str_contains(ReplayToken::parse($a)->target, '{NS}')) {
                return $a;
            }
            $token = ReplayToken::parse($a);

            return (new ReplayToken(str_replace('{NS}', $project->namespace, $token->target), $token->seed, $token->k, null))->toString();
        }, $arguments);
        self::pront($project, $arguments);

        self::assertJournalDone($project);
    }

    public function testAConfigurationErrorEndsItsJournal(): void
    {
        $project = new Project(['pront.php' => "<?php\nreturn [];\n"]);
        self::assertSame(2, self::pront($project, [])[0]);

        self::assertJournalDone($project);
    }

    private static function assertJournalDone(Project $project): void
    {
        $files = glob($project->directory . '/.pront/runs/*.jsonl') ?: [];
        self::assertCount(1, $files);
        $records = \Pront\Tests\Engine\Harness::records($files[0]);
        self::assertSame(['type' => 'done'], end($records));
    }

    public function testReplayOfAnUnverifiableTokenExitsWithTwo(): void
    {
        $project = self::project("/** @param int<0, 10> \$x */\n#[Verify(seed: 1)] #[\\Pront\\Attribute\\Ensures(static function (int \$return): bool { return true; })]\n"
            . "public static function m(int \$x): int|string { return \$x > 5 ? 's' : \$x; }");
        [, $stdout] = self::pront($project, []);

        [$code, $replay] = self::pront($project, ['--replay', self::token($stdout)]);
        self::assertSame(2, $code, $replay);
        self::assertStringContainsString('UNVERIFIABLE A::m: the replay failed', $project->relative($replay));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badTokens(): iterable
    {
        yield 'not a token' => ['abc', 'Error: A replay token starts with "pront:"; nothing was replayed.'];
        yield 'damaged' => ['pront:!!', 'Error: The replay token is damaged; nothing was replayed.'];
        yield 'another version' => [(new ReplayToken('A::m', 1, 1, null, '0.0.0-old'))->toString(), 'was made by Pront 0.0.0-old'];
        yield 'another algorithm' => [(new ReplayToken('A::m', 1, 1, null, Version::ID, 'pront-0'))->toString(), 'uses algorithm pront-0'];
        yield 'a target that is not verified' => [(new ReplayToken('Nope::m', 1, 1, null))->toString(), 'Error: the replay token names Nope::m, which is not a verified target of this run; nothing was replayed.'];
    }

    #[DataProvider('badTokens')]
    public function testABadReplayTokenExitsWithTwo(string $token, string $expected): void
    {
        $project = self::project('#[Verify] public static function m(): int { return 1; }');

        [$code, $stdout] = self::pront($project, ['--replay', $token]);
        self::assertSame(2, $code, $stdout);
        self::assertStringContainsString($expected, $stdout);
    }

    public function testAMemoryFatalPrintsTheReplayTokenAndExitsWithOne(): void
    {
        $project = self::project('#[Verify(iterations: 1)] public static function m(): void { ini_set("memory_limit", "16M"); $a = []; while (true) { $a[] = str_repeat("x", 1 << 16); } }');

        [$code, $stdout, $stderr] = self::pront($project, ['--seed', '5']);
        self::assertSame(1, $code, $stderr);
        self::assertStringStartsWith('Pront ' . Version::ID . " (seed 5, algorithm pront-1)\n", $stdout);
        self::assertSame(1, preg_match('/^Replay token: (pront:\S+)$/m', $stderr, $match), $stderr);
        self::assertStringContainsString('Allowed memory size', $stderr);

        [$code, , $stderr] = self::pront($project, ['--replay', $match[1]]);
        self::assertSame(1, $code, $stderr);
        self::assertStringContainsString("Replay token: {$match[1]}", $stderr);

        // Both crashes were reported by their own run, so a later run does not print them again.
        file_put_contents($project->directory . '/src/A.php', str_replace('{NS}', $project->namespace, self::php('#[Verify(iterations: 1)] public static function m(): void {}')));
        [$code, $stdout] = self::pront($project, ['--seed', '5']);
        self::assertSame(0, $code, $stdout);
        self::assertStringNotContainsString('Replay token', $stdout);
    }

    public function testAKilledRunIsPrintedOnceByTheNextRun(): void
    {
        if (!function_exists('posix_kill')) {
            self::markTestSkipped('needs the posix extension');
        }
        $project = self::project('#[Verify(iterations: 1)] public static function m(): void { if (is_file(__DIR__ . "/../kill")) { posix_kill(posix_getpid(), SIGKILL); } }');
        touch($project->directory . '/kill');

        [$code, $stdout] = self::pront($project, ['--seed', '9']);
        self::assertNotSame(0, $code);
        self::assertSame('Pront ' . Version::ID . " (seed 9, algorithm pront-1)\n\n", $stdout);

        unlink($project->directory . '/kill');
        [$code, $stdout] = self::pront($project, ['--seed', '9']);
        $stdout = $project->relative($stdout);
        self::assertSame(0, $code, $stdout);
        self::assertSame(1, preg_match(
            '/\APront \S+ \(seed 9, algorithm pront-1\)\nPront: run \S+ stopped during a fresh attempt of A::m \(fresh trial 1\); nothing was re-run\.\nReplay token: (pront:\S+)\n/',
            $stdout,
            $match,
        ), $stdout);
        $token = ReplayToken::parse($match[1]);
        self::assertSame([1, null], [$token->k, $token->buffer]);
        [$code, $replay] = self::pront($project, ['--replay', $match[1]]);
        self::assertSame(0, $code, $replay);
        self::assertStringContainsString('  The input was regenerated from the seed: if the parameters of the target changed', $replay);

        self::assertStringContainsString('; 1 crashed attempt of earlier runs', $stdout);

        [, $stdout] = self::pront($project, ['--seed', '9']);
        self::assertStringNotContainsString('Replay token', $stdout);
        self::assertStringContainsString('; 0 crashed attempts of earlier runs', $stdout);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function usageErrors(): iterable
    {
        yield 'unknown option' => [['--bogus'], 'Pront: Unknown option --bogus'];
        yield 'unknown option with a value' => [['--iterations=5'], 'Pront: Unknown option --iterations'];
        yield 'positional argument' => [['src'], 'Pront: Unexpected argument "src"'];
        yield 'short option' => [['-v'], 'Pront: Unexpected argument "-v"'];
        yield 'seed without value' => [['--seed'], 'Pront: --seed needs a value'];
        yield 'empty seed' => [['--seed='], 'Pront: --seed needs a value'];
        yield 'seed not an int' => [['--seed', '1.5'], 'Pront: --seed must be an integer'];
        yield 'seed out of range' => [['--seed', '9223372036854775808'], 'Pront: --seed must be an integer'];
        yield 'seed with a plus sign' => [['--seed', '+1'], 'Pront: --seed must be an integer'];
        yield 'edge ratio is a #[Verify] matter, not an option' => [['--edge-ratio', '1'], 'Pront: Unknown option --edge-ratio'];
        yield 'repeated option' => [['--strict', '--strict'], 'Pront: --strict is given more than once'];
        yield 'flag with a value' => [['--strict=1'], 'Pront: --strict takes no value'];
        yield 'seed with replay' => [['--seed', '1', '--replay', 'pront:x'], 'Pront: --seed has no effect with --replay'];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('usageErrors')]
    public function testUsageErrorsExitWithTwo(array $arguments, string $expected): void
    {
        [$code, $stdout, $stderr] = self::runBin([PHP_BINARY, self::BIN, ...$arguments], sys_get_temp_dir());

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertStringStartsWith($expected, $stderr);
        self::assertStringContainsString('Usage: vendor/bin/pront [options]', $stderr);
    }

    public function testHelpAndVersion(): void
    {
        [$code, $stdout, $stderr] = self::runBin([PHP_BINARY, self::BIN, '--help'], sys_get_temp_dir());
        self::assertSame([0, ''], [$code, $stderr]);
        self::assertStringStartsWith('Pront ' . Version::ID . "\n\nUsage: vendor/bin/pront [options]\n", $stdout);

        [$code, $stdout, $stderr] = self::runBin([PHP_BINARY, self::BIN, '--version'], sys_get_temp_dir());
        self::assertSame([0, 'Pront ' . Version::ID . "\n", ''], [$code, $stdout, $stderr]);
    }

    /**
     * @return iterable<string, array{array<string, string>, list<string>, string}>
     */
    public static function configurationErrors(): iterable
    {
        yield 'no pront.php' => [[], [], 'Error: the configuration file '];
        yield 'missing --config file' => [[], ['--config', 'nope.php'], 'Error: the configuration file nope.php does not exist; nothing was verified.'];
        yield 'not a Config' => [['pront.php' => "<?php\nreturn [];\n"], [], 'Error: configuration: '];
        yield 'throws' => [['pront.php' => "<?php\nthrow new \\RuntimeException('boom');\n"], [], 'RuntimeException: boom; nothing was verified.'];
        yield 'paths that do not exist' => [['pront.php' => "<?php\nreturn new \\Pront\\Config\\Config(['nope']);\n"], [], "Pre-run errors (1):\n  - pront.php: paths: nope does not exist"];
    }

    /**
     * @param array<string, string> $files
     * @param list<string> $arguments
     */
    #[DataProvider('configurationErrors')]
    public function testConfigurationErrorsExitWithTwo(array $files, array $arguments, string $expected): void
    {
        $project = new Project($files);

        [$code, $stdout, $stderr] = self::pront($project, ['--seed', '1', ...$arguments]);
        self::assertSame(2, $code, $stdout);
        self::assertSame('', $stderr);
        self::assertStringStartsWith('Pront ' . Version::ID . " (seed 1, algorithm pront-1)\n", $stdout);
        self::assertStringContainsString($expected, $stdout);
    }

    public function testConfigOptionNamesTheBaseDirectory(): void
    {
        $project = self::project('#[Verify(iterations: 1)] public static function m(): int { return 1; }');

        [$code, $stdout] = self::runBin([PHP_BINARY, self::BIN, '--config', $project->directory . '/pront.php'], sys_get_temp_dir());
        self::assertSame(0, $code, $stdout);
        self::assertStringContainsString('PASS ', $stdout);
        self::assertDirectoryExists($project->directory . '/.pront/runs');
    }

    /**
     * Installed as a dependency, bin/pront runs through a Composer bin proxy, which sets
     * $_composer_autoload_path and includes the script instead of executing it.
     */
    public function testLoadsTheAutoloaderTheComposerProxyGives(): void
    {
        [$exitCode, $stdout, $stderr] = self::runBin([
            PHP_BINARY,
            '-r',
            '$GLOBALS["_composer_autoload_path"] = $argv[1]; $_SERVER["argv"] = [$argv[2], "--version"]; include $argv[2];',
            '--',
            __DIR__ . '/Fixtures/proxy-autoload.php',
            self::BIN,
        ], sys_get_temp_dir());

        self::assertSame(0, $exitCode);
        self::assertSame('Pront ' . Version::ID . "\n", $stdout);
        self::assertSame("proxy autoloader loaded\n", $stderr);
    }

    private static function project(string $body): Project
    {
        return new Project(['pront.php' => self::CONFIG, 'src/A.php' => self::php($body)]);
    }

    private static function php(string $body): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {NS};\n\nuse Pront\\Attribute\\Verify;\n\n"
            . "enum Suit { case Hearts; case Spades; }\n\nclass A\n{\n{$body}\n}\n";
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private static function pront(Project $project, array $arguments): array
    {
        return self::runBin([PHP_BINARY, '-d', 'display_errors=stderr', self::BIN, ...$arguments], $project->directory);
    }

    private static function token(string $stdout): string
    {
        self::assertSame(1, preg_match('/--replay (pront:\S+)/', $stdout, $match), $stdout);

        return $match[1];
    }

    /**
     * @return list<string>
     */
    private static function targetSeeds(string $stdout): array
    {
        preg_match_all('/seed (-?\d+)\)/', $stdout, $matches);

        return $matches[1];
    }

    /**
     * @param list<string> $expected
     */
    private static function assertContainsInOrder(array $expected, string $actual): void
    {
        $offset = 0;
        foreach ($expected as $fragment) {
            $position = strpos($actual, $fragment, $offset);
            self::assertNotFalse($position, "Expected, after offset {$offset}: {$fragment}\nin:\n{$actual}");
            $offset = $position + strlen($fragment);
        }
    }

    /**
     * Output goes to temporary files rather than pipes, so a child that writes more than a pipe
     * buffer to either stream cannot deadlock the test.
     *
     * @param list<string> $command
     * @return array{int, string, string}
     */
    private static function runBin(array $command, string $cwd): array
    {
        $stdoutFile = tmpfile();
        $stderrFile = tmpfile();
        self::assertIsResource($stdoutFile);
        self::assertIsResource($stderrFile);
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $stdoutFile, 2 => $stderrFile], $pipes, $cwd);
        self::assertIsResource($process);
        $exitCode = proc_close($process);

        $read = static function ($file): string {
            rewind($file);
            return (string) stream_get_contents($file);
        };

        return [$exitCode, $read($stdoutFile), $read($stderrFile)];
    }
}
