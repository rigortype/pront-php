<?php

declare(strict_types=1);

namespace Pront\ChoiceBuffer;

/**
 * The float draw layout (ADR-0002 sections 2 and 3). The first slot is the `kind`:
 *
 * - kind 0, `[0, magnitude, side]`: a small integer value, drawn as an `int` with the `IntOrder`
 *   rule over [-2^53, 2^53] (every integer there is exactly a float), within +/-16 when fresh;
 * - kind 1, `[1, high, low, sign]`: a general finite value, high 31 and low 32 magnitude bits and the
 *   sign bit of its IEEE 754 pattern;
 * - kind 2, `[2, index]`: an entry of the float edge table (ADR-0007 section 1 by default).
 *
 * Kind 1 rejects every pattern that has another encoding or none: non-finite values, `-0.0`,
 * integer values inside the kind 0 range, and the bit pattern of any edge-table entry. So no value
 * has two encodings. Fresh, kind 2 is chosen with the edge ratio (10% by default, ADR-0002 section 3
 * calls the kind 2 weight "the edge ratio"); kinds 0 and 1 share the rest 20:70.
 */
final class FloatCodec
{
    /** ADR-0007 section 1, in table order. */
    public const array ADR_0007_EDGES = [-0.0, NAN, INF, -INF, PHP_FLOAT_EPSILON, PHP_FLOAT_MIN, PHP_FLOAT_MAX];

    /** Kind 0 covers the integers in [-KIND0_LIMIT, KIND0_LIMIT]. */
    public const int KIND0_LIMIT = 1 << 53;

    /** Fresh kind 0 values lie in [-FRESH_KIND0_LIMIT, FRESH_KIND0_LIMIT]. */
    public const int FRESH_KIND0_LIMIT = 16;

    /** Fresh weights of kinds 0 and 1 (ADR-0002 section 3: 20% and 70% at the default edge ratio). */
    public const array KIND_WEIGHTS = [20, 70];

    /** The default edge ratio, the fresh probability of kind 2 (ADR-0007 section 2). */
    public const float DEFAULT_EDGE_RATIO = 0.1;

    /** @var list<float> */
    public readonly array $edges;

    /** @var array<int, true> edge-table bit patterns */
    private readonly array $edgeBits;

    /**
     * @param list<float> $edges the float edge table; each entry must have no kind 0 or kind 1 encoding
     *                           other than its own index (distinct bit patterns, no integer value in
     *                           the kind 0 range except `-0.0`)
     */
    public function __construct(array $edges = self::ADR_0007_EDGES)
    {
        $bits = [];
        foreach ($edges as $edge) {
            $pattern = self::bits($edge);
            if (isset($bits[$pattern])) {
                throw new \InvalidArgumentException('The float edge table has a duplicate entry');
            }
            if ($pattern !== self::bits(-0.0) && self::isKindZeroValue($edge)) {
                throw new \InvalidArgumentException(sprintf('%s is a kind 0 value and cannot be a float edge', var_export($edge, true)));
            }
            $bits[$pattern] = true;
        }
        $this->edges = array_values($edges);
        $this->edgeBits = $bits;
    }

    /**
     * The fresh slots of one float draw; kind 2 with probability `$edgeRatio` (never with an empty
     * edge table).
     *
     * @return list<int>
     */
    public function freshSlots(Prng $prng, float $edgeRatio = self::DEFAULT_EDGE_RATIO): array
    {
        if (!($edgeRatio >= 0.0 && $edgeRatio <= 1.0)) {
            throw new \InvalidArgumentException('The edge ratio must be within [0, 1]');
        }
        $kind = $this->edges !== [] && $prng->chance($edgeRatio) ? 2 : $prng->weighted(self::KIND_WEIGHTS);

        return match ($kind) {
            0 => [0, ...IntOrder::encode(
                $prng->intInRange(-self::FRESH_KIND0_LIMIT, self::FRESH_KIND0_LIMIT),
                -self::KIND0_LIMIT,
                self::KIND0_LIMIT,
            )],
            1 => $this->freshKindOne($prng),
            default => [2, $prng->uniformUnsigned(count($this->edges) - 1)],
        };
    }

    /**
     * The one encoding of `$value`, or `InvalidArgumentException` when it has none (a NaN pattern
     * other than the table's, or a value the edge table leaves out such as `-0.0` with a custom table).
     *
     * @return list<int>
     */
    public function encode(float $value): array
    {
        $pattern = self::bits($value);
        if (isset($this->edgeBits[$pattern])) {
            foreach ($this->edges as $index => $edge) {
                if (self::bits($edge) === $pattern) {
                    return [2, $index];
                }
            }
        }
        if ($pattern !== self::bits(-0.0) && self::isKindZeroValue($value)) {
            return [0, ...IntOrder::encode((int) $value, -self::KIND0_LIMIT, self::KIND0_LIMIT)];
        }
        if ($this->isKindOnePattern($pattern)) {
            return [1, ($pattern >> 32) & 0x7FFFFFFF, $pattern & 0xFFFFFFFF, ($pattern >> 63) & 1];
        }

        throw new \InvalidArgumentException(sprintf('float bit pattern %016x has no encoding', $pattern));
    }

    public function kindZero(int $magnitude, int $side): float
    {
        return (float) IntOrder::decode($magnitude, $side, -self::KIND0_LIMIT, self::KIND0_LIMIT);
    }

    public function kindOne(int $high, int $low, int $sign): float
    {
        if ($high < 0 || $high > 0x7FFFFFFF || $low < 0 || $low > 0xFFFFFFFF || ($sign !== 0 && $sign !== 1)) {
            throw new InvalidChoice("float kind 1 slots ({$high}, {$low}, {$sign}) are out of range");
        }
        $pattern = ($high << 32) | $low | ($sign << 63);
        if (!$this->isKindOnePattern($pattern)) {
            throw new InvalidChoice(sprintf('float bit pattern %016x has no kind 1 encoding', $pattern));
        }

        return self::fromBits($pattern);
    }

    public function kindTwo(int $index): float
    {
        if ($index < 0 || $index >= count($this->edges)) {
            throw new InvalidChoice("float edge index {$index} is outside the table");
        }

        return $this->edges[$index];
    }

    /**
     * Whether `$pattern` is the kind 1 encoding of its value (see the class comment).
     */
    public function isKindOnePattern(int $pattern): bool
    {
        $value = self::fromBits($pattern);

        return is_finite($value)
            && $pattern !== self::bits(-0.0)
            && !self::isKindZeroValue($value)
            && !isset($this->edgeBits[$pattern]);
    }

    /**
     * The IEEE 754 bit pattern of `$value` as an `int`.
     */
    public static function bits(float $value): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('P', pack('e', $value));

        return $unpacked[1];
    }

    public static function fromBits(int $pattern): float
    {
        /** @var array{1: float} $unpacked */
        $unpacked = unpack('e', pack('P', $pattern));

        return $unpacked[1];
    }

    private static function isKindZeroValue(float $value): bool
    {
        return is_finite($value) && floor($value) === $value && abs($value) <= (float) self::KIND0_LIMIT;
    }

    /**
     * @return list<int>
     */
    private function freshKindOne(Prng $prng): array
    {
        do {
            $pattern = $prng->nextRaw();
        } while (!$this->isKindOnePattern($pattern));

        return $this->encode(self::fromBits($pattern));
    }
}
