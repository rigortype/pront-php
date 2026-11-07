<?php

declare(strict_types=1);

namespace Pront\Cli;

use Pront\ChoiceBuffer\ChoiceBuffer;
use Pront\ChoiceBuffer\Draw;
use Pront\ChoiceBuffer\Prng;
use Pront\Config\ConfigLoader;
use Pront\Config\InvalidConfiguration;
use Pront\Discovery\Discovery;
use Pront\Discovery\DiscoveryResult;
use Pront\Discovery\Target;
use Pront\Engine\Algorithm;
use Pront\Engine\Engine;
use Pront\Engine\InvalidReplayToken;
use Pront\Engine\ReplayToken;
use Pront\Engine\TargetReport;
use Pront\Engine\TargetStatus;
use Pront\Generator\ConstructorGenerator;
use Pront\Journal\Journal;
use Pront\Journal\RecoveredAttempt;
use Pront\Oracle\FailureKind;
use Pront\Oracle\Oracle;
use Pront\Shrinker\OutcomeKind;
use Pront\Shrinker\StopReason;
use Pront\Version;

/**
 * The Stage 1 runner behind `vendor/bin/pront` (ADR-0013 section 3, ADR-0004, ADR-0014 section 3):
 * text output and exit codes 0, 1 and 2.
 *
 * A run prints, in this order: the run seed and the algorithm identifier on the first line
 * (ADR-0004 item 3); the replay tokens of attempts that earlier runs left unfinished (item 8); every
 * pre-run error, the classes "unverified until Stage 3", the reserved-name conflicts and the
 * unchecked return-type fragments, before any target runs; one verdict per verified target as it
 * finishes; the unchecked exceptions; and the summary line, which always shows every count, zero
 * included. Nothing found is left out (ADR-0008 scope principles).
 *
 * Exit code: 1 when a counterexample was found (flaky ones included), whatever else happened;
 * otherwise 2 for a pre-run or configuration error, nothing to verify, an unverifiable trial or too
 * many discards, and with `--strict` also for an unchecked fragment, a reserved-name conflict, a
 * skipped implementation or a class "unverified until Stage 3"; otherwise 0.
 *
 * A replay prints the input it runs first. A recorded buffer that no longer replays as recorded
 * (repaired, or with other draw kinds: the target's parameters changed) never reports "no longer
 * reproduces": a pass is then a replay error (exit 2). Unfinished attempts of earlier runs are
 * counted on the summary line, which is all that changes for them.
 *
 * The report goes to `$out`; a usage error and an internal error go to `$err`. A fatal error
 * during an attempt is reported by the journal's shutdown function on STDERR (ADR-0004 item 6).
 */
final class Application
{
    /** The command a printed replay hint starts with. */
    private const string COMMAND = 'vendor/bin/pront';

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    private Arguments $arguments;

    /** Unfinished attempts of earlier runs printed by this run (ADR-0004 item 8). */
    private int $recovered = 0;

    /**
     * @param list<string> $argv the command line without the script name
     * @param resource $out
     * @param resource $err
     */
    public function run(array $argv, $out, $err): int
    {
        $this->out = $out;
        $this->err = $err;
        try {
            $this->arguments = Arguments::parse($argv);
        } catch (UsageError $e) {
            fwrite($err, "Pront: {$e->getMessage()}\n\n" . Arguments::USAGE . "\n");

            return ExitCode::ERROR;
        }
        if ($this->arguments->help) {
            fwrite($out, 'Pront ' . Version::ID . "\n\n" . Arguments::USAGE . "\n");

            return ExitCode::SUCCESS;
        }
        if ($this->arguments->version) {
            fwrite($out, 'Pront ' . Version::ID . "\n");

            return ExitCode::SUCCESS;
        }

        try {
            return $this->arguments->replay === null ? $this->verify() : $this->replay($this->arguments->replay);
        } catch (\Throwable $e) {
            // A Pront bug outside any attempt (an attempt's own failures never reach here).
            fwrite($err, sprintf("Pront: internal error: %s: %s\n%s\nThe run did not finish; its result is unknown.\n", $e::class, $e->getMessage(), $e->getTraceAsString()));

            return ExitCode::ERROR;
        }
    }

    private function verify(): int
    {
        $seed = $this->arguments->seed ?? self::randomSeed();
        $this->line(sprintf('Pront %s (seed %d, algorithm %s)', Version::ID, $seed, Algorithm::ID));

        $prepared = $this->prepare();
        if ($prepared === null) {
            return ExitCode::ERROR;
        }
        [$journal, $discovery] = $prepared;
        $oracle = new Oracle();
        $engine = new Engine($journal, $oracle);
        $tally = new Tally($this->recovered);
        $this->preRun($discovery, $tally);

        if ($discovery->targets === [] && $discovery->errors === []) {
            $this->line('');
            $this->line('Nothing to verify: the paths of the configuration contain no #[Verify].');
        }
        foreach ($discovery->targets as $target) {
            $this->line('');
            $this->targetReport($target, $engine->verify($target, $seed), $tally);
        }
        $journal->finish();

        $this->uncheckedExceptions($oracle, $tally);
        $this->line('');
        $this->line($tally->summary());

        return $tally->exitCode($this->arguments->strict);
    }

    private function replay(string $text): int
    {
        try {
            $token = ReplayToken::parse($text);
        } catch (InvalidReplayToken $e) {
            $this->line(sprintf('Pront %s (replay, algorithm %s)', Version::ID, Algorithm::ID));
            $this->line("Error: {$e->getMessage()}; nothing was replayed.");

            return ExitCode::ERROR;
        }
        // The token's target seed is the seed of everything this run draws (ADR-0004 item 3).
        $this->line(sprintf('Pront %s (replay, seed %d, algorithm %s)', Version::ID, $token->seed, $token->algorithm));

        $prepared = $this->prepare();
        if ($prepared === null) {
            return ExitCode::ERROR;
        }
        [$journal, $discovery] = $prepared;
        $oracle = new Oracle();
        $engine = new Engine($journal, $oracle);
        $tally = new Tally($this->recovered);
        $this->preRun($discovery, $tally);

        $this->line('');
        $target = null;
        foreach ($discovery->targets as $candidate) {
            if ($candidate->name() === $token->target) {
                $target = $candidate;
            }
        }
        if ($target === null) {
            $journal->finish();
            $this->line("Error: the replay token names {$token->target}, which is not a verified target of this run; nothing was replayed.");
            $tally->replayError();
            $this->line('');
            $this->line($tally->summary());

            return ExitCode::ERROR;
        }

        $this->line(sprintf(
            'Replaying %s: fresh trial %d of seed %d, %s',
            $token->target,
            $token->k,
            $token->seed,
            $token->buffer === null ? 'regenerated from the seed' : sprintf('recorded buffer of %d draws', count($token->buffer)),
        ));
        $buffer = $token->buffer === null
            ? ChoiceBuffer::fresh(Prng::forTrial($token->seed, $token->k))
            : ChoiceBuffer::repairing($token->buffer);
        $this->line('  Input:');
        $this->input($target, $buffer, $token->buffer);
        // A recorded buffer that was repaired, or not read draw for draw with the recorded kinds, no
        // longer describes the recorded input (the target's parameters changed): a pass then proves
        // nothing.
        $kinds = static fn(array $draws): array => array_map(static fn(Draw $draw): string => $draw->kind->name, $draws);
        $fits = $token->buffer === null
            || (!$buffer->repaired() && $kinds($buffer->consumed()) === $kinds($token->buffer));
        if (!$fits) {
            $this->line("  The recorded buffer no longer fits {$token->target} (it was repaired, or its draws do not match the parameters): the input above is not the recorded one.");
        }

        $result = $engine->replay($token, $discovery);
        $journal->finish();
        $outcome = $result->outcome;
        if ($outcome->kind === OutcomeKind::Failed) {
            $unverifiable = FailureKind::of($outcome) === FailureKind::Unverifiable;
            $this->line(($unverifiable ? 'UNVERIFIABLE' : 'FAIL') . " {$token->target}: the replay failed");
            $this->line("  Failure: {$outcome->signature}");
            $tally->replayed($unverifiable ? 'unverifiable' : 'failed');
        } elseif ($outcome->kind === OutcomeKind::Invalid || !$fits) {
            $this->line("ERROR {$token->target}: the recorded buffer no longer produces an input for it (did its parameter types change?)");
            $tally->replayError();
        } else {
            $this->line("PASS {$token->target}: no longer reproduces"
                . ($outcome->kind === OutcomeKind::Discarded ? ' (the input was discarded: ' . ($outcome->reason ?? 'no reason given') . ')' : ''));
            if ($result->note !== null) {
                $this->line("  {$result->note}");
            }
            $tally->replayed('passed');
        }

        $this->uncheckedExceptions($oracle, $tally);
        $this->line('');
        $this->line($tally->summary());

        return $tally->exitCode($this->arguments->strict);
    }

    /**
     * Opens the journal (printing what earlier runs left unfinished), evaluates the configuration
     * and discovers the targets. Null after an error was printed.
     *
     * @return array{Journal, DiscoveryResult}|null
     */
    private function prepare(): ?array
    {
        $file = $this->arguments->config ?? ((string) getcwd() . '/' . ConfigLoader::FILE_NAME);
        $real = realpath($file);
        if ($real === false || !is_file($real)) {
            $this->line("Error: the configuration file {$file} does not exist; nothing was verified.");

            return null;
        }
        $base = dirname($real);

        try {
            $journal = Journal::start($base, function (RecoveredAttempt $attempt): void {
                $this->recovered++;
                $this->line($attempt->message());
            });
        } catch (\RuntimeException $e) {
            $this->line("Error: {$e->getMessage()}; nothing was verified.");

            return null;
        }
        try {
            $config = ConfigLoader::load($real);
        } catch (InvalidConfiguration $e) {
            $journal->finish();
            $this->line("Error: configuration: {$e->getMessage()}; nothing was verified.");

            return null;
        }
        $discovery = (new Discovery())->discover($config, $base);

        return [$journal, $discovery];
    }

    /**
     * Everything discovery found that is not a verified target, before any target runs.
     */
    private function preRun(DiscoveryResult $discovery, Tally $tally): void
    {
        $tally->discovered($discovery);
        $this->section('Pre-run errors', array_map(strval(...), $discovery->errors));
        $this->section('Unverified until Stage 3 (ADR-0012)', array_map(
            static fn(string $class, string $verified): string => "{$class} extends {$verified}",
            array_keys($discovery->unverifiedUntilStage3),
            $discovery->unverifiedUntilStage3,
        ));
        $conflicts = [];
        foreach ($discovery->targets as $target) {
            array_push($conflicts, ...$target->reservedNameConflicts);
        }
        $this->section('Reserved-name conflicts (ADR-0009)', $conflicts);
        $this->section('Unchecked return-type fragments (ADR-0009)', array_map(strval(...), Oracle::uncheckedFragments($discovery->targets)));
    }

    private function targetReport(Target $target, TargetReport $report, Tally $tally): void
    {
        $name = $report->target;
        $counts = sprintf('%s, %d passed, %d discarded, seed %d', Tally::plural($report->trials, 'trial'), $report->passed, $report->discards, $report->seed);
        if ($report->status === TargetStatus::Passed) {
            $tally->verified('passed');
            $this->line("PASS {$name} ({$counts})");

            return;
        }
        if ($report->status === TargetStatus::TooManyDiscards) {
            $tally->verified('discards');
            $this->line("ERROR {$name}: too many discards, a generator-configuration error ({$counts})");
            $this->line('  ' . ($report->message ?? ''));

            return;
        }

        $counterexample = $report->counterexample ?? throw new \LogicException("{$name} failed without a counterexample");
        $unverifiable = FailureKind::of($counterexample->failure) === FailureKind::Unverifiable;
        $flaky = $report->status === TargetStatus::Flaky;
        $tally->verified($unverifiable ? 'unverifiable' : ($flaky ? 'flaky' : 'failed'));
        $this->line(sprintf(
            '%s %s (fresh trial %d failed; %s)',
            $unverifiable ? 'UNVERIFIABLE' : ($flaky ? 'FLAKY' : 'FAIL'),
            $name,
            $counterexample->k,
            $counts,
        ));
        $this->line("  Failure: {$counterexample->failure->signature}");
        if ($unverifiable) {
            $this->line('  A condition raised a TypeError binding a value: the trial is neither a pass nor a counterexample (ADR-0009 section 4).');
        }
        $shrink = $counterexample->shrink;
        $this->line(sprintf(
            '  %s (shrunk in %d evaluations, %d accepted; %s):',
            $unverifiable ? 'Input' : 'Counterexample',
            $shrink->evaluations,
            $shrink->accepted,
            match ($shrink->stopReason) {
                StopReason::Fixpoint => 'a local minimum',
                StopReason::Budget => 'stopped by the evaluation budget, possibly not minimal',
                StopReason::Time => 'stopped by the time limit, possibly not minimal',
            },
        ));
        $this->input($target, ChoiceBuffer::repairing($counterexample->buffer), $counterexample->buffer);
        $this->line(sprintf(
            '  Re-run %d times: reproduced %d times%s.',
            $counterexample->reruns,
            $counterexample->reproduced,
            !$flaky ? '' : ($unverifiable ? '; not reproducible (flaky)' : '; not reproducible (flaky), still counted as a counterexample'),
        ));
        $this->line('  Replay: ' . $this->replayCommand($counterexample->token));
    }

    /**
     * Shows the input `$buffer` produces, drawn the way the engine draws it (receiver first, then
     * the parameters), without running user code: a receiver built by its constructor is shown as
     * the constructor call with its drawn arguments.
     *
     * @param list<Draw>|null $recorded the recorded buffer, to tell draws it never made
     */
    private function input(Target $target, ChoiceBuffer $buffer, ?array $recorded): void
    {
        try {
            if ($target->receiver instanceof ConstructorGenerator) {
                $arguments = [];
                foreach ($target->receiver->arguments->generate($buffer) as $name => $value) {
                    $arguments[] = "{$name}: " . ValueRenderer::render($value);
                }
                $this->line('    $self = new \\' . $target->receiver->class . '(' . implode(', ', $arguments) . ')');
            } elseif ($target->receiver !== null) {
                $this->line('    $self = ' . ValueRenderer::render($target->receiver->generate($buffer)));
            }
            $afterReceiver = count($buffer->consumed());
            $parameters = $target->arguments->generate($buffer);
            if ($recorded !== null && count($buffer->consumed()) > count($recorded) && $afterReceiver >= count($recorded)) {
                $this->line('    (the attempt stopped while building $self: the parameters below were not drawn)');
            }
            foreach ($parameters as $name => $value) {
                $this->line("    \${$name} = " . ValueRenderer::render($value));
            }
            if ($parameters === [] && $target->receiver === null) {
                $this->line('    (no parameters)');
            }
        } catch (\Throwable $e) {
            $this->line('    (the input cannot be shown: ' . $e::class . ": {$e->getMessage()})");
        }
    }

    private function uncheckedExceptions(Oracle $oracle, Tally $tally): void
    {
        $unchecked = $oracle->uncheckedExceptions();
        $tally->uncheckedExceptions(count($unchecked));
        if ($unchecked === []) {
            return;
        }
        $this->line('');
        $this->section('Unchecked exceptions (ADR-0009 section 5)', array_map(
            static fn(string $target, int $attempts): string => sprintf(
                '%s: %d %s ended in an Exception, which a method without @throws may throw; its return type and #[Ensures] were not checked for %s',
                $target,
                $attempts,
                $attempts === 1 ? 'attempt' : 'attempts',
                $attempts === 1 ? 'it' : 'them',
            ),
            array_keys($unchecked),
            $unchecked,
        ));
    }

    private function replayCommand(ReplayToken $token): string
    {
        $parts = [self::COMMAND];
        if ($this->arguments->config !== null) {
            $parts[] = '--config ' . escapeshellarg($this->arguments->config);
        }
        $parts[] = '--replay ' . $token->toString();

        return implode(' ', $parts);
    }

    /**
     * @param list<string> $items
     */
    private function section(string $title, array $items): void
    {
        if ($items === []) {
            return;
        }
        $this->line(sprintf('%s (%d):', $title, count($items)));
        foreach ($items as $item) {
            $this->line("  - {$item}");
        }
    }

    private function line(string $text): void
    {
        fwrite($this->out, $text . "\n");
    }

    /**
     * A run seed when `--seed` is not given; drawn from the system's CSPRNG, outside the choice
     * buffer (it only picks which seed the run uses, and is printed).
     */
    private static function randomSeed(): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('q', random_bytes(8));

        return $unpacked[1];
    }
}
