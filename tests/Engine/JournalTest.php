<?php

declare(strict_types=1);

namespace Pront\Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pront\ChoiceBuffer\Draw;
use Pront\ChoiceBuffer\DrawKind;
use Pront\Engine\AttemptKind;
use Pront\Engine\ReplayToken;
use Pront\Journal\Journal;
use Pront\Journal\RecoveredAttempt;
use Pront\Shrinker\Outcome;
use Pront\Tests\Discovery\Project;

final class JournalTest extends TestCase
{
    private static function runs(string $directory): string
    {
        $runs = $directory . '/' . Journal::DIRECTORY;
        if (!is_dir($runs)) {
            mkdir($runs, 0o700, true);
        }

        return $runs;
    }

    /**
     * Starts and finishes a run in `$directory`; returns the tokens it recovered.
     *
     * @return list<string>
     */
    private static function recover(string $directory): array
    {
        $tokens = [];
        Journal::start($directory, static function (RecoveredAttempt $attempt) use (&$tokens): void {
            $tokens[] = $attempt->token;
            self::assertStringEndsWith("Replay token: {$attempt->token}", $attempt->message());
        })->finish();

        return $tokens;
    }

    private static function begin(int $n, int $k, ?array $buffer = null): string
    {
        $token = (new ReplayToken('App\A::m', 99, $k, $buffer))->toString();

        return json_encode(['type' => 'begin', 'n' => $n, 'attempt' => 'fresh', 'target' => 'App\A::m', 'seed' => 99, 'k' => $k, 'buffer' => null, 'token' => $token], JSON_THROW_ON_ERROR);
    }

    private static function token(int $k): string
    {
        return (new ReplayToken('App\A::m', 99, $k, null))->toString();
    }

    public function testAttemptsAreAppendedAndTheFileIsLockedForTheRunsLife(): void
    {
        $project = new Project([]);
        $journal = Journal::start($project->directory, static fn() => null, 'run-1');
        $buffer = [new Draw(DrawKind::Selector, [0]), new Draw(DrawKind::Int, [3, 1])];

        self::assertSame($project->directory . '/.pront/runs/run-1.jsonl', $journal->file);
        $other = fopen($journal->file, 'r');
        self::assertIsResource($other);
        self::assertFalse(flock($other, LOCK_EX | LOCK_NB), 'locked while the run lives');

        $first = $journal->begin(AttemptKind::Fresh, 'App\A::m', 7, 1, null);
        $journal->end($first, Outcome::passed());
        $second = $journal->begin(AttemptKind::Shrink, 'App\A::m', 7, 1, $buffer);
        $journal->end($second, Outcome::failed('sig'));
        $journal->finish();

        self::assertTrue(flock($other, LOCK_EX | LOCK_NB), 'unlocked once the run is done');
        fclose($other);
        self::assertSame([
            ['type' => 'begin', 'n' => 1, 'attempt' => 'fresh', 'target' => 'App\A::m', 'seed' => 7, 'k' => 1, 'buffer' => null, 'token' => (new ReplayToken('App\A::m', 7, 1, null))->toString()],
            ['type' => 'end', 'n' => 1, 'outcome' => 'passed'],
            ['type' => 'begin', 'n' => 2, 'attempt' => 'shrink', 'target' => 'App\A::m', 'seed' => 7, 'k' => 1, 'buffer' => [['Selector', [0]], ['Int', [3, 1]]], 'token' => (new ReplayToken('App\A::m', 7, 1, $buffer))->toString()],
            ['type' => 'end', 'n' => 2, 'outcome' => 'failed'],
            ['type' => 'done'],
        ], Harness::records($journal->file));
        self::assertSame(['run-1.jsonl'], array_values(array_diff(scandir(self::runs($project->directory)) ?: [], ['.', '..'])));
    }

    public function testAttemptsDoNotOverlap(): void
    {
        $journal = Journal::start((new Project([]))->directory, static fn() => null);
        $journal->begin(AttemptKind::Fresh, 'A::m', 1, 1, null);

        try {
            $journal->begin(AttemptKind::Fresh, 'A::m', 1, 2, null);
            self::fail('expected a LogicException');
        } catch (\LogicException) {
        }
        $journal->end(1, null);
        $journal->finish();
        self::assertSame('error', Harness::records($journal->file)[1]['outcome']);
    }

    /**
     * Earlier run files, the tokens a later run start reports, and whether it marks the file
     * `reported`.
     *
     * @return iterable<string, array{list<string>, list<int>, bool}> lines, reported trial numbers k, marked
     */
    public static function earlierRuns(): iterable
    {
        yield 'begin without end' => [[self::begin(1, 1)], [1], true];
        yield 'last of several attempts in flight' => [[self::begin(1, 1), '{"type":"end","n":1,"outcome":"passed"}', self::begin(2, 2)], [2], true];
        yield 'a record cut short by the crash' => [[self::begin(1, 1), '{"type":"end","n":1,"outcome":"passed"}', self::begin(2, 3), '{"type":"en'], [3], true];
        yield 'every attempt ended, no done' => [[self::begin(1, 1), '{"type":"end","n":1,"outcome":"passed"}'], [], false];
        yield 'done' => [[self::begin(1, 1), '{"type":"end","n":1,"outcome":"passed"}', '{"type":"done"}'], [], false];
        yield 'fatal, already reported by its own run' => [[self::begin(1, 1), '{"type":"fatal","n":1,"message":"m"}', '{"type":"reported","by":"x"}'], [], false];
        yield 'fatal whose own report did not finish' => [[self::begin(1, 1), '{"type":"fatal","n":1,"message":"m"}'], [1], true];
        yield 'reported by an earlier recovery' => [[self::begin(1, 1), '{"type":"reported","by":"x"}'], [], false];
        yield 'no attempt' => [[], [], false];
    }

    /**
     * @param list<string> $lines
     * @param list<int> $ks
     */
    #[DataProvider('earlierRuns')]
    public function testCrashRecoveryReportsAnUnfinishedAttemptOnce(array $lines, array $ks, bool $marked): void
    {
        $project = new Project([]);
        $file = self::runs($project->directory) . '/earlier.jsonl';
        // A file ends in a newline unless its last record was cut short.
        $content = implode("\n", $lines) . ($lines === [] || json_decode((string) end($lines)) === null ? '' : "\n");
        file_put_contents($file, $content);

        self::assertSame(array_map(self::token(...), $ks), self::recover($project->directory));
        self::assertSame([], self::recover($project->directory), 'reported once only');

        $after = (string) file_get_contents($file);
        if ($marked) {
            self::assertStringStartsWith($content, $after);
            $appended = explode("\n", rtrim($after, "\n"));
            $last = json_decode((string) end($appended), true);
            self::assertIsArray($last);
            self::assertSame('reported', $last['type']);
        } else {
            self::assertSame($content, $after, 'nothing to report: the file is left as it was');
        }
    }

    public function testAFileLockedByALiveRunIsNotScanned(): void
    {
        $project = new Project([]);
        $file = self::runs($project->directory) . '/live.jsonl';
        file_put_contents($file, self::begin(1, 4) . "\n");
        $live = fopen($file, 'r');
        self::assertIsResource($live);
        self::assertTrue(flock($live, LOCK_EX));

        self::assertSame([], self::recover($project->directory));
        self::assertSame(self::begin(1, 4) . "\n", file_get_contents($file));

        flock($live, LOCK_UN);
        fclose($live);
        self::assertSame([self::token(4)], self::recover($project->directory));
    }

    public function testRotationKeepsTheNewest20AndOnlyDeletesDoneReportedOrEmptyFiles(): void
    {
        $project = new Project([]);
        $runs = self::runs($project->directory);
        $now = time();
        for ($i = 0; $i < 25; $i++) {
            file_put_contents("{$runs}/old-{$i}.jsonl", self::begin(1, 1) . "\n" . '{"type":"end","n":1,"outcome":"passed"}' . "\n" . '{"type":"done"}' . "\n");
            touch("{$runs}/old-{$i}.jsonl", $now - 1000 + $i);
        }
        $stale = [
            'stale-ended' => self::begin(1, 1) . "\n" . '{"type":"end","n":1,"outcome":"passed"}' . "\n",
            'stale-empty' => '',
            'stale-crash' => self::begin(1, 6) . "\n",
            'stale-fatal' => self::begin(1, 1) . "\n" . '{"type":"fatal","n":1,"message":"m"}' . "\n" . '{"type":"reported","by":"x"}' . "\n",
            'stale-locked' => '{"type":"done"}' . "\n",
        ];
        foreach ($stale as $name => $content) {
            file_put_contents("{$runs}/{$name}.jsonl", $content);
            touch("{$runs}/{$name}.jsonl", $now - 5000);
        }
        file_put_contents("{$runs}/not-a-run.txt", 'x');
        $live = fopen("{$runs}/stale-locked.jsonl", 'r');
        self::assertIsResource($live);
        self::assertTrue(flock($live, LOCK_EX));

        $tokens = [];
        $journal = Journal::start($project->directory, static function (RecoveredAttempt $a) use (&$tokens): void {
            $tokens[] = $a->token;
        }, 'zz-current');
        flock($live, LOCK_UN);
        fclose($live);

        self::assertSame([self::token(6)], $tokens, 'an old crash is reported before its file is rotated away');
        $expected = array_map(static fn(int $i): string => "old-{$i}.jsonl", range(5, 24));
        $expected = array_merge($expected, ['not-a-run.txt', 'stale-ended.jsonl', 'stale-locked.jsonl', 'zz-current.jsonl']);
        sort($expected);
        $remaining = array_values(array_diff(scandir($runs) ?: [], ['.', '..']));
        self::assertSame($expected, $remaining);
        $journal->finish();
    }

    public function testAKilledProcessLeavesOnlyBeginWhichALaterRunReports(): void
    {
        $project = new Project(['src/A.php' => Harness::php(
            'final class A { #[Verify] public static function m(int $x): void { echo "ready\n"; fflush(STDOUT); sleep(30); } }',
        )]);
        $script = Harness::script($project->directory, '$engine->run($discovery, 5); $journal->finish();');
        $process = proc_open([PHP_BINARY, $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        self::assertSame("ready\n", fgets($pipes[1]));
        proc_terminate($process, 9);
        proc_close($process);

        $files = glob(self::runs($project->directory) . '/*.jsonl') ?: [];
        self::assertCount(1, $files);
        $records = Harness::records($files[0]);
        self::assertSame(['begin'], array_column($records, 'type'));

        $tokens = self::recover($project->directory);
        self::assertCount(1, $tokens);
        $token = ReplayToken::parse($tokens[0]);
        self::assertSame([$records[0]['token'], 1, null], [$tokens[0], $token->k, $token->buffer]);
        self::assertStringEndsWith('\A::m', $token->target);
    }

    /**
     * @return iterable<string, array{string, list<string>, string}> method body, ini settings, error text
     */
    public static function fatals(): iterable
    {
        yield 'memory limit, one large allocation' => ['$s = str_repeat("x", 256 * 1024 * 1024);', ['memory_limit=32M'], 'Allowed memory size of'];
        yield 'memory limit, memory used up' => ['$a = []; while (true) { $a[] = str_repeat("x", 100); }', ['memory_limit=32M'], 'Allowed memory size of'];
        yield 'time limit' => ['while (true) { $x = 1; }', ['max_execution_time=1'], 'Maximum execution time of 1 second'];
    }

    /**
     * @param list<string> $ini
     */
    #[DataProvider('fatals')]
    public function testAFatalErrorIsJournaledAndReportedWithExitCode1(string $body, array $ini, string $error): void
    {
        $project = new Project(['src/A.php' => Harness::php(
            "final class A { #[Verify] public static function m(int \$x): void { {$body} } }",
        )]);
        $script = Harness::script($project->directory, '$engine->run($discovery, 5); $journal->finish(); echo "finished";');

        [$status, $stdout, $stderr] = Harness::runScript($script, $ini);

        self::assertSame(1, $status, $stderr);
        self::assertSame('', $stdout);
        self::assertStringContainsString($error, $stderr);
        self::assertSame(1, preg_match('/^Replay token: (pront:\S+)$/m', $stderr, $match), $stderr);
        $files = glob(self::runs($project->directory) . '/*.jsonl') ?: [];
        self::assertCount(1, $files);
        $records = Harness::records($files[0]);
        self::assertSame(['begin', 'fatal', 'reported'], array_column($records, 'type'));
        self::assertSame($match[1], $records[0]['token']);
        self::assertStringContainsString($error, $records[1]['message']);
        self::assertSame(1, ReplayToken::parse($match[1])->k);

        self::assertSame([], self::recover($project->directory), 'a crash its own run reported is not printed again');
    }

    public function testRunsStartingTogetherReportACrashOnce(): void
    {
        for ($round = 0; $round < 3; $round++) {
            $project = new Project([]);
            file_put_contents(self::runs($project->directory) . '/crashed.jsonl', self::begin(1, 8) . "\n");
            $go = $project->directory . '/go';
            $script = $project->directory . '/start.php';
            file_put_contents($script, sprintf(
                '<?php require %s; while (!file_exists(%s)) { usleep(1000); }
                $j = Pront\Journal\Journal::start(%s, static function ($a): void { echo $a->token, "\n"; });
                usleep(50000); $j->finish();',
                var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
                var_export($go, true),
                var_export($project->directory, true),
            ));
            $processes = [];
            for ($i = 0; $i < 4; $i++) {
                $process = proc_open([PHP_BINARY, $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $processes[] = [$process, $pipes];
            }
            touch($go);
            $printed = [];
            foreach ($processes as [$process, $pipes]) {
                $stdout = (string) stream_get_contents($pipes[1]);
                $stderr = (string) stream_get_contents($pipes[2]);
                self::assertSame(0, proc_close($process), $stderr);
                array_push($printed, ...array_filter(explode("\n", $stdout)));
            }

            self::assertSame([self::token(8)], $printed, "round {$round}");
        }
    }
}
