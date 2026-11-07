<?php

declare(strict_types=1);

namespace Pront\Cli;

use Pront\Discovery\DiscoveryResult;
use Pront\Oracle\Oracle;

/**
 * The counts of one run: the summary line (ADR-0013 section 3: it always shows the skipped and
 * unchecked items, the reserved-name conflicts and the classes "unverified until Stage 3", zero
 * included) and the exit code.
 */
final class Tally
{
    private bool $replay = false;

    private int $targets = 0;

    /** @var array{passed: int, failed: int, flaky: int, unverifiable: int, discards: int} */
    private array $verdicts = ['passed' => 0, 'failed' => 0, 'flaky' => 0, 'unverifiable' => 0, 'discards' => 0];

    private int $preRunErrors = 0;

    private int $discovered = 0;

    private int $replayErrors = 0;

    /** Stage 1 skips no implementation: contract inheritance arrives in Stage 3 (ADR-0012). */
    private int $skipped = 0;

    private int $uncheckedFragments = 0;

    private int $uncheckedExceptions = 0;

    private int $conflicts = 0;

    private int $unverified = 0;

    public function discovered(DiscoveryResult $discovery): void
    {
        $this->discovered = count($discovery->targets);
        $this->preRunErrors = count($discovery->errors);
        $this->unverified = count($discovery->unverifiedUntilStage3);
        $this->uncheckedFragments = count(Oracle::uncheckedFragments($discovery->targets));
        $this->conflicts = 0;
        foreach ($discovery->targets as $target) {
            $this->conflicts += count($target->reservedNameConflicts);
        }
    }

    /**
     * @param 'passed'|'failed'|'flaky'|'unverifiable'|'discards' $verdict
     */
    public function verified(string $verdict): void
    {
        $this->targets++;
        $this->verdicts[$verdict]++;
    }

    /**
     * @param 'passed'|'failed'|'unverifiable' $verdict
     */
    public function replayed(string $verdict): void
    {
        $this->replay = true;
        $this->verified($verdict);
    }

    /** A replay token naming no verified target, or a recorded buffer that no longer fits it. */
    public function replayError(): void
    {
        $this->replay = true;
        $this->replayErrors++;
    }

    public function uncheckedExceptions(int $targets): void
    {
        $this->uncheckedExceptions = $targets;
    }

    public function summary(): string
    {
        $v = $this->verdicts;
        $parts = [
            sprintf(
                '%s: %d passed, %d failed, %d flaky, %d unverifiable, %d too many discards',
                $this->replay ? self::plural($this->targets, 'replayed attempt') : self::plural($this->targets, 'target'),
                $v['passed'],
                $v['failed'],
                $v['flaky'],
                $v['unverifiable'],
                $v['discards'],
            ),
        ];
        if ($this->replay) {
            $parts[] = self::plural($this->replayErrors, 'replay error');
        }
        $parts[] = self::plural($this->preRunErrors, 'pre-run error');
        $parts[] = "{$this->skipped} skipped";
        $parts[] = sprintf(
            '%d unchecked (%s, %s)',
            $this->uncheckedFragments + $this->uncheckedExceptions,
            self::plural($this->uncheckedFragments, 'return-type fragment'),
            self::plural($this->uncheckedExceptions, 'target', 'targets') . ' with unchecked exceptions',
        );
        $parts[] = self::plural($this->conflicts, 'reserved-name conflict');
        $parts[] = "{$this->unverified} unverified until Stage 3";

        return 'Summary: ' . implode('; ', $parts);
    }

    public function exitCode(bool $strict): int
    {
        $v = $this->verdicts;
        if ($v['failed'] + $v['flaky'] > 0) {
            return ExitCode::COUNTEREXAMPLE;
        }
        $nothing = !$this->replay && $this->discovered === 0;
        if ($this->preRunErrors > 0 || $this->replayErrors > 0 || $nothing || $v['unverifiable'] > 0 || $v['discards'] > 0) {
            return ExitCode::ERROR;
        }
        if ($strict && $this->skipped + $this->uncheckedFragments + $this->conflicts + $this->unverified > 0) {
            return ExitCode::ERROR;
        }

        return ExitCode::SUCCESS;
    }

    public static function plural(int $n, string $singular, ?string $plural = null): string
    {
        return $n . ' ' . ($n === 1 ? $singular : ($plural ?? $singular . 's'));
    }
}
