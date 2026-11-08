<?php

declare(strict_types=1);

namespace Pront\Journal;

use Pront\ChoiceBuffer\Draw;
use Pront\Engine\AttemptKind;
use Pront\Engine\ReplayToken;
use Pront\Shrinker\Outcome;

/**
 * The run journal (ADR-0004 items 5, 6 and 8): one JSON Lines file per run,
 * `.pront/runs/<run-id>.jsonl`, locked with `flock` for the run's life and only ever appended to;
 * every record is flushed when written.
 *
 * Records (one JSON object per line, `type` first):
 * - `begin` (`n`, `attempt`, `target`, `seed`, `k`, `buffer`, `token`): written before any user code
 *   of attempt `n` runs; `buffer` is the recorded buffer as `[kind, slots]` pairs, or null for a
 *   fresh trial (regenerated from seed and k);
 * - `end` (`n`, `outcome`): the attempt, including the release of its input, has finished;
 * - `fatal` (`n`, `message`): written best-effort by the shutdown function;
 * - `reported` (`by`): the file's unfinished attempt has been reported, by the shutdown function of
 *   its own run or by the crash recovery of a later run (`by` names that run);
 * - `done`: the run finished normally.
 *
 * `start()` creates the file under a hidden temporary name, locks it and only then renames it into
 * place, so another run never sees it unlocked. It then scans every other run file whose lock it
 * can take without waiting (a file whose lock is held belongs to a live run and is left alone): an
 * attempt with a `begin` but no `end` in a file that is neither `done` nor `reported` is handed to
 * the recovery callback and the file is marked `reported`, while its lock is held, so two runs
 * starting together never both report it. An attempt with a `fatal` record is in such a file only
 * when its own run's report did not finish (it writes `reported` after STDERR), so it is reported
 * again, with the fatal message, rather than risk a token nobody saw. Then the scanned files are rotated: the newest
 * `KEEP` (by modification time before this scan, then by name) are kept, and of the others only
 * files that are `done`, `reported`, or hold no attempt are deleted.
 */
final class Journal
{
    public const int KEEP = 20;

    public const string DIRECTORY = '.pront/runs';

    /** Error types after which PHP stops the script; `error_get_last()` is used only for these. */
    private const int FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

    /** @var resource|null */
    private $handle;

    private int $attempts = 0;

    /** @var array{n: int, kind: string, target: string, token: string}|null */
    private ?array $inFlight = null;

    /**
     * @param resource $handle
     */
    private function __construct(
        public readonly string $runId,
        public readonly string $file,
        $handle,
    ) {
        $this->handle = $handle;
    }

    /**
     * Opens the journal of a new run under `$baseDirectory/.pront/runs`, reports the unfinished
     * attempts of earlier runs through `$onRecovered`, rotates old run files and registers the
     * shutdown function.
     *
     * @param callable(RecoveredAttempt): void $onRecovered
     */
    public static function start(string $baseDirectory, callable $onRecovered, ?string $runId = null): self
    {
        $directory = rtrim($baseDirectory, '/') . '/' . self::DIRECTORY;
        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create the run journal directory {$directory}");
        }
        $runId ??= gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $runId) !== 1) {
            throw new \InvalidArgumentException("Invalid run id {$runId}");
        }
        $file = "{$directory}/{$runId}.jsonl";
        $temporary = "{$directory}/.{$runId}.jsonl.new";
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            throw new \RuntimeException("Cannot create the run journal {$temporary}");
        }
        if (!flock($handle, LOCK_EX) || file_exists($file) || !rename($temporary, $file)) {
            fclose($handle);
            @unlink($temporary);
            throw new \RuntimeException("Cannot create the run journal {$file}");
        }

        $journal = new self($runId, $file, $handle);
        $journal->recoverAndRotate($directory, $onRecovered);
        register_shutdown_function($journal->onShutdown(...));

        return $journal;
    }

    /**
     * Writes the `begin` record of a new attempt and clears the last error, so the shutdown function
     * only reports a fatal error raised during this attempt. Returns the attempt number.
     *
     * @param list<Draw>|null $buffer the recorded buffer the attempt runs, null for a fresh trial
     */
    public function begin(AttemptKind $kind, string $target, int $seed, int $k, ?array $buffer): int
    {
        if ($this->inFlight !== null) {
            throw new \LogicException("Attempt {$this->inFlight['n']} has not ended");
        }
        $token = (new ReplayToken($target, $seed, $k, $buffer))->toString();
        $n = ++$this->attempts;
        $this->write([
            'type' => 'begin',
            'n' => $n,
            'attempt' => $kind->value,
            'target' => $target,
            'seed' => $seed,
            'k' => $k,
            'buffer' => $buffer === null ? null : ReplayToken::encodeBuffer($buffer),
            'token' => $token,
        ]);
        $this->inFlight = ['n' => $n, 'kind' => $kind->value, 'target' => $target, 'token' => $token];
        error_clear_last();

        return $n;
    }

    /**
     * Writes the `end` record of attempt `$n`; `$outcome` is null when the attempt stopped with an
     * exception Pront does not classify (it propagates).
     */
    public function end(int $n, ?Outcome $outcome): void
    {
        if ($this->inFlight === null || $this->inFlight['n'] !== $n) {
            throw new \LogicException("Attempt {$n} is not in flight");
        }
        $this->write(['type' => 'end', 'n' => $n, 'outcome' => $outcome === null ? 'error' : strtolower($outcome->kind->name)]);
        $this->inFlight = null;
    }

    /**
     * Writes the `done` record and releases the lock: the run finished normally.
     */
    public function finish(): void
    {
        if ($this->inFlight !== null) {
            throw new \LogicException("Attempt {$this->inFlight['n']} has not ended");
        }
        $this->write(['type' => 'done']);
        $handle = $this->handle();
        flock($handle, LOCK_UN);
        fclose($handle);
        $this->handle = null;
    }

    /**
     * The shutdown function (ADR-0004 item 6). Without an attempt in flight it does nothing. With
     * one, it lifts the memory limit, records `fatal`, writes the message and the replay token to
     * STDERR, marks the file `reported` and ends the process with exit code 1.
     *
     * @internal registered by `start()`
     */
    public function onShutdown(): void
    {
        if ($this->inFlight === null || $this->handle === null) {
            return;
        }
        ini_set('memory_limit', '-1');
        $error = error_get_last();
        $message = $error !== null && ($error['type'] & self::FATAL_ERRORS) !== 0
            ? "{$error['message']} in {$error['file']}:{$error['line']}"
            : 'the process ended without a fatal error (exit, die or a shutdown function in the code under test is undefined behaviour, ADR-0004 item 7)';
        $attempt = $this->inFlight;
        // Whatever the journal could record, the token reaches STDERR and the status is 1.
        $stderr = fopen('php://stderr', 'w') ?: null;
        $record = function (array $record) use ($stderr): void {
            try {
                $this->write($record);
            } catch (\Throwable $failure) {
                // Not covered by a test: making the append fail inside a shutdown function needs
                // a full disk or a revoked descriptor.
                if ($stderr !== null) {
                    fwrite($stderr, "Pront: could not write the {$record['type']} record to {$this->file}: {$failure->getMessage()}\n");
                }
            }
        };
        $record(['type' => 'fatal', 'n' => $attempt['n'], 'message' => $message]);
        if ($stderr !== null) {
            fwrite($stderr, sprintf(
                "Pront: the run stopped during a %s attempt of %s: %s\nReplay token: %s\n",
                $attempt['kind'],
                $attempt['target'],
                $message,
                $attempt['token'],
            ));
            fflush($stderr);
        }
        $record(['type' => 'reported', 'by' => $this->runId]);
        exit(1);
    }

    /**
     * @param callable(RecoveredAttempt): void $onRecovered
     */
    private function recoverAndRotate(string $directory, callable $onRecovered): void
    {
        $files = glob("{$directory}/*.jsonl") ?: [];
        sort($files);
        /** @var list<array{path: string, handle: resource, mtime: int, deletable: bool}> $scanned */
        $scanned = [];
        try {
            foreach ($files as $path) {
                if ($path === $this->file) {
                    continue;
                }
                $handle = @fopen($path, 'r+');
                if ($handle === false) {
                    continue;
                }
                if (!flock($handle, LOCK_EX | LOCK_NB)) {
                    fclose($handle);
                    continue;
                }
                $stat = fstat($handle);
                $scanned[] = [
                    'path' => $path,
                    'handle' => $handle,
                    'mtime' => $stat === false ? 0 : $stat['mtime'],
                    'deletable' => $this->recover($path, $handle, $onRecovered),
                ];
            }
            usort($scanned, static fn(array $a, array $b): int => [$b['mtime'], $b['path']] <=> [$a['mtime'], $a['path']]);
            foreach (array_slice($scanned, self::KEEP) as $old) {
                if ($old['deletable']) {
                    @unlink($old['path']);
                }
            }
        } finally {
            foreach ($scanned as $entry) {
                flock($entry['handle'], LOCK_UN);
                fclose($entry['handle']);
            }
        }
    }

    /**
     * Reports the unfinished attempts of one locked run file and marks it `reported`. Returns
     * whether rotation may delete the file: it is `done`, `reported` (now or before), or holds no
     * attempt.
     *
     * @param resource $handle
     * @param callable(RecoveredAttempt): void $onRecovered
     */
    private function recover(string $path, $handle, callable $onRecovered): bool
    {
        $done = false;
        $reported = false;
        /** @var array<int, array<string, mixed>> $open begin records without end */
        $open = [];
        /** @var array<int, string> $fatal attempt => message of its fatal record */
        $fatal = [];
        $attempts = 0;
        $content = (string) stream_get_contents($handle, null, 0);
        foreach (explode("\n", $content) as $line) {
            $record = json_decode($line, true);
            if (!is_array($record) || !is_string($record['type'] ?? null)) {
                continue; // a line cut short by a crash, or not a record
            }
            $n = $record['n'] ?? null;
            switch ($record['type']) {
                case 'begin':
                    if (is_int($n)) {
                        $open[$n] = $record;
                        $attempts++;
                    }
                    break;
                case 'end':
                    unset($open[$n]);
                    break;
                case 'fatal':
                    if (is_int($n)) {
                        $fatal[$n] = is_string($record['message'] ?? null) ? $record['message'] : '';
                    }
                    break;
                case 'reported':
                    $reported = true;
                    break;
                case 'done':
                    $done = true;
                    break;
            }
        }
        if ($done || $reported) {
            return true;
        }
        if ($open === []) {
            return $attempts === 0;
        }
        $runId = basename($path, '.jsonl');
        foreach ($open as $n => $begin) {
            $onRecovered(new RecoveredAttempt(
                $runId,
                is_string($begin['attempt'] ?? null) ? $begin['attempt'] : '?',
                is_string($begin['target'] ?? null) ? $begin['target'] : '?',
                is_int($begin['k'] ?? null) ? $begin['k'] : 0,
                is_string($begin['token'] ?? null) ? $begin['token'] : '?',
                $fatal[$n] ?? null,
            ));
        }
        fseek($handle, 0, SEEK_END);
        if ($content !== '' && !str_ends_with($content, "\n")) {
            fwrite($handle, "\n"); // end the record a crash cut short, so the next one parses
        }
        self::append($handle, ['type' => 'reported', 'by' => $this->runId]);

        return true;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function write(array $record): void
    {
        self::append($this->handle(), $record);
    }

    /**
     * @param resource $handle
     * @param array<string, mixed> $record
     */
    private static function append($handle, array $record): void
    {
        $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        if (fwrite($handle, $line) !== strlen($line) || !fflush($handle)) {
            throw new \RuntimeException('Cannot write to the run journal');
        }
    }

    /**
     * @return resource
     */
    private function handle()
    {
        return $this->handle ?? throw new \LogicException('The run journal is closed');
    }
}
