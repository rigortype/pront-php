<?php

declare(strict_types=1);

namespace Pront\Tests\Engine;

use Pront\Config\Config;
use Pront\Discovery\Discovery;
use Pront\Discovery\DiscoveryResult;
use Pront\Discovery\Target;
use Pront\Engine\Input;
use Pront\Shrinker\Outcome;
use Pront\Tests\Discovery\Project;

/**
 * Shared fixtures of the engine and journal tests: throwaway projects, their discovery, a stub
 * property that calls the target, and the records of a journal file.
 */
final class Harness
{
    public static function php(string $body): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {NS};

            use Pront\\Attribute\\Verify;

            {$body}
            PHP;
    }

    /**
     * @param array<string, string> $files
     * @return array{Project, DiscoveryResult}
     */
    public static function discover(array $files): array
    {
        $project = new Project($files);
        $result = (new Discovery())->discover(new Config(['src']), $project->directory);
        if ($result->errors !== []) {
            throw new \LogicException('Unexpected pre-run errors: ' . implode('; ', array_map(strval(...), $result->errors)));
        }

        return [$project, $result];
    }

    /**
     * The stub property: calls the target with the input; it passes unless the call throws.
     */
    public static function call(Target $target, Input $input): Outcome
    {
        $target->method->invokeArgs($target->method->isStatic() ? null : $input->receiver, $input->arguments);

        return Outcome::passed();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function records(string $file): array
    {
        $records = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                throw new \LogicException("Not a record: {$line}");
            }
            $records[] = $record;
        }

        return $records;
    }

    /**
     * Runs a PHP script with stdin from /dev/null.
     *
     * @param list<string> $iniSettings
     * @return array{int, string, string} exit code, stdout, stderr
     */
    public static function runScript(string $script, array $iniSettings = []): array
    {
        $command = [PHP_BINARY, '-d', 'display_errors=stderr'];
        foreach ($iniSettings as $setting) {
            $command[] = '-d';
            $command[] = $setting;
        }
        $command[] = $script;
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start PHP');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * A script that discovers `$directory`, starts a journal there and runs `$body` with
     * `$discovery`, `$journal` and `$engine` (the stub property) in scope.
     */
    public static function script(string $directory, string $body): string
    {
        $script = $directory . '/run-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, sprintf(
            <<<'PHP'
                <?php
                declare(strict_types=1);
                require %1$s;
                $discovery = (new Pront\Discovery\Discovery())->discover(new Pront\Config\Config(['src']), %2$s);
                $journal = Pront\Journal\Journal::start(%2$s, static function (Pront\Journal\RecoveredAttempt $a): void { echo $a->token, "\n"; });
                $engine = new Pront\Engine\Engine($journal, Pront\Tests\Engine\Harness::call(...));
                %3$s
                PHP,
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($directory, true),
            $body,
        ));

        return $script;
    }
}
