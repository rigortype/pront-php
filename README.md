# Pront - Declarative Property-based Testing for PHP

Pront verifies the contracts written on your methods (`#[Verify]`, `#[Ensures]`, types, `@throws`)
against generated inputs. Stage 1 is a proof of concept; see `docs/adr/0014-runtime-and-releases.md`
for what it covers.

## Usage

`pront.php` in the project root returns the configuration:

```php
<?php
return new Pront\Config\Config(paths: ['src'], exclude: ['tests']);
```

Run `vendor/bin/pront`. Options:

| Option | Effect |
| --- | --- |
| `--config <file>` | the configuration file (default: `pront.php` in the current directory) |
| `--seed <int>` | the run seed; without it a random seed is used |
| `--strict` | unchecked return-type fragments, reserved-name conflicts and classes "unverified until Stage 3" fail the run |
| `--replay <token>` | re-run one attempt from its replay token |

The first line of every run shows the run seed and the algorithm identifier, so a log of a failing
run can be reproduced with `--seed`. The report lists every pre-run error and unchecked item and ends
with a summary line.

Exit codes: 0 when no counterexample and no error was found (unchecked items are listed but do not
change it without `--strict`); 1 when a counterexample was found (also a flaky one, and
whatever else happened in the run); 2 for a usage, configuration or pre-run error, a run with nothing
to verify, an unverifiable trial or too many discards, and, with `--strict`, for the listed items.
CI should run `vendor/bin/pront --strict`.

### Replay and crashes

Each counterexample is printed with a `--replay` command. Trials run in the Pront process, and every
attempt is journaled under `.pront/runs/` before it runs (add `.pront/` to `.gitignore`). If a
trial ends the process with a fatal error (memory or time limit), Pront prints the replay token on
STDERR and exits with 1. If the process is killed, the next run prints the unfinished attempt's
replay token once; nothing is re-run automatically.

A replay reproduces Pront's inputs of one attempt, not static or global state that earlier trials
left behind, so a replay that passes does not prove the bug is gone.

### Limits

- An infinite loop in the code under test stops the whole run: wrap the command in `timeout`, for
  example `timeout 600 vendor/bin/pront --strict`. The journal keeps the hung attempt reproducible.
- Code under test that calls `exit` or `die`, registers its own shutdown functions, or is declared
  `never` has undefined behaviour: the run may report a pass, a crash or nothing.
- Pront executes the code it verifies. Put `#[Verify]` only on methods without harmful side effects.
