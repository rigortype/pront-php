<?php

declare(strict_types=1);

namespace Pront\Cli;

/**
 * The command line of `vendor/bin/pront` (ADR-0013 section 3, ADR-0014 section 3). Each option is
 * `--name value` or `--name=value` and may be given once; anything else is a usage error (exit
 * code 2), never ignored.
 */
final readonly class Arguments
{
    public const string USAGE = <<<'TXT'
        Usage: vendor/bin/pront [options]

        Options:
          --config <file>      the configuration file (default: pront.php in the current directory)
          --seed <int>         the run seed (default: random; printed on the first line)
          --strict             unchecked fragments, reserved-name conflicts and classes
                               "unverified until Stage 3" give exit code 2
          --replay <token>     re-run the attempt a replay token names
          --version            print the version
          --help               print this help

        Exit codes: 0 no counterexample and no error (with --strict, also none of the items it
        names); 1 a counterexample was found; 2 an error (nothing or not everything was verified).
        TXT;

    private const array VALUED = ['config', 'seed', 'replay'];

    private const array FLAGS = ['strict', 'version', 'help'];

    private function __construct(
        public ?string $config,
        public ?int $seed,
        public ?string $replay,
        public bool $strict,
        public bool $version,
        public bool $help,
    ) {
    }

    /**
     * @param list<string> $arguments the command line without the script name
     * @throws UsageError
     */
    public static function parse(array $arguments): self
    {
        $values = [];
        $flags = [];
        for ($i = 0; $i < count($arguments); $i++) {
            $argument = $arguments[$i];
            if ($argument === '-h') {
                $argument = '--help';
            }
            if (!str_starts_with($argument, '--') || $argument === '--') {
                throw new UsageError("Unexpected argument \"{$argument}\"");
            }
            $name = substr($argument, 2);
            $value = null;
            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            }
            if (isset($values[$name]) || isset($flags[$name])) {
                throw new UsageError("--{$name} is given more than once");
            }
            if (in_array($name, self::FLAGS, true)) {
                if ($value !== null) {
                    throw new UsageError("--{$name} takes no value");
                }
                $flags[$name] = true;
            } elseif (in_array($name, self::VALUED, true)) {
                if ($value === null) {
                    if (!isset($arguments[$i + 1])) {
                        throw new UsageError("--{$name} needs a value");
                    }
                    $value = $arguments[++$i];
                }
                if ($value === '') {
                    throw new UsageError("--{$name} needs a value");
                }
                $values[$name] = $value;
            } else {
                throw new UsageError("Unknown option --{$name}");
            }
        }

        $seed = null;
        if (isset($values['seed'])) {
            $seed = filter_var($values['seed'], FILTER_VALIDATE_INT);
            if ($seed === false || preg_match('/\A-?[0-9]+\z/', $values['seed']) !== 1) {
                throw new UsageError("--seed must be an integer within PHP's int range, \"{$values['seed']}\" given");
            }
        }
        if (isset($values['replay']) && isset($values['seed'])) {
            throw new UsageError('--seed has no effect with --replay: the token carries the seed');
        }

        return new self(
            $values['config'] ?? null,
            $seed,
            $values['replay'] ?? null,
            isset($flags['strict']),
            isset($flags['version']),
            isset($flags['help']),
        );
    }
}
