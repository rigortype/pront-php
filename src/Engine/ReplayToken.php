<?php

declare(strict_types=1);

namespace Pront\Engine;

use Pront\ChoiceBuffer\Draw;
use Pront\ChoiceBuffer\DrawKind;
use Pront\Version;

/**
 * What `--replay` takes to re-run one attempt (GLOSSARY, ADR-0004 items 8 and 9, ADR-0011 section
 * 1): the Pront version, the algorithm identifier, the target name, the target seed, the fresh
 * trial number `k` and, for an attempt that ran a recorded buffer (a shrink candidate, a flaky
 * re-run, the counterexample), that buffer with each draw's kind, so it replays through
 * `ChoiceBuffer::repairing()` exactly as it ran. Without a buffer, the input is regenerated from
 * (seed, k) alone.
 *
 * Text form: `pront:` followed by the unpadded base64url encoding of the JSON object
 * `{"v": version, "a": algorithm, "t": target, "s": seed, "k": k, "b": null | [[kind, [slot, ...]], ...]}`,
 * where `kind` is a `DrawKind` case name. It contains no shell metacharacters.
 */
final readonly class ReplayToken
{
    public const string PREFIX = 'pront:';

    /**
     * @param list<Draw>|null $buffer
     */
    public function __construct(
        public string $target,
        public int $seed,
        public int $k,
        public ?array $buffer,
        public string $version = Version::ID,
        public string $algorithm = Algorithm::ID,
    ) {
        if ($k < 1) {
            throw new \InvalidArgumentException('A fresh trial number k starts at 1');
        }
    }

    /**
     * @throws InvalidReplayToken when the text is not a token, or one from another Pront version or
     *     algorithm (replay is only guaranteed within one Pront version, ADR-0011 section 1)
     */
    public static function parse(string $text): self
    {
        $text = trim($text);
        if (!str_starts_with($text, self::PREFIX)) {
            throw new InvalidReplayToken('A replay token starts with "' . self::PREFIX . '"');
        }
        $json = base64_decode(strtr(substr($text, strlen(self::PREFIX)), '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data)
            || !is_string($data['v'] ?? null) || !is_string($data['a'] ?? null) || !is_string($data['t'] ?? null)
            || !is_int($data['s'] ?? null) || !is_int($data['k'] ?? null) || $data['k'] < 1
            || !array_key_exists('b', $data)
        ) {
            throw new InvalidReplayToken('The replay token is damaged');
        }
        if ($data['v'] !== Version::ID) {
            throw new InvalidReplayToken(sprintf(
                'The replay token was made by Pront %s; this is Pront %s, and replay is only guaranteed within one version',
                $data['v'],
                Version::ID,
            ));
        }
        if ($data['a'] !== Algorithm::ID) {
            throw new InvalidReplayToken(sprintf(
                'The replay token uses algorithm %s; this Pront uses %s',
                $data['a'],
                Algorithm::ID,
            ));
        }

        return new self($data['t'], $data['s'], $data['k'], self::buffer($data['b']), $data['v'], $data['a']);
    }

    public function toString(): string
    {
        $json = json_encode([
            'v' => $this->version,
            'a' => $this->algorithm,
            't' => $this->target,
            's' => $this->seed,
            'k' => $this->k,
            'b' => $this->buffer === null ? null : self::encodeBuffer($this->buffer),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return self::PREFIX . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * A buffer in its JSON form: one `[kind, slots]` pair per draw. The journal uses the same form.
     *
     * @param list<Draw> $buffer
     * @return list<array{string, list<int<0, max>>}>
     */
    public static function encodeBuffer(array $buffer): array
    {
        return array_map(static fn(Draw $draw): array => [$draw->kind->name, $draw->slots], $buffer);
    }

    /**
     * @return list<Draw>|null
     * @throws InvalidReplayToken
     */
    private static function buffer(mixed $data): ?array
    {
        if ($data === null) {
            return null;
        }
        if (!is_array($data) || !array_is_list($data)) {
            throw new InvalidReplayToken('The replay token is damaged: its buffer is not a list');
        }
        $kinds = [];
        foreach (DrawKind::cases() as $case) {
            $kinds[$case->name] = $case;
        }
        $draws = [];
        foreach ($data as $draw) {
            if (!is_array($draw) || !array_is_list($draw) || count($draw) !== 2
                || !is_string($draw[0]) || !isset($kinds[$draw[0]])
                || !is_array($draw[1]) || !array_is_list($draw[1])
            ) {
                throw new InvalidReplayToken('The replay token is damaged: a draw is not a [kind, slots] pair');
            }
            $slots = [];
            foreach ($draw[1] as $slot) {
                if (!is_int($slot) || $slot < 0) {
                    throw new InvalidReplayToken('The replay token is damaged: a slot is not a non-negative int');
                }
                $slots[] = $slot;
            }
            $draws[] = new Draw($kinds[$draw[0]], $slots);
        }

        return $draws;
    }
}
