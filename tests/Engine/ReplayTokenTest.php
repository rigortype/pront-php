<?php

declare(strict_types=1);

namespace Pront\Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pront\ChoiceBuffer\Draw;
use Pront\ChoiceBuffer\DrawKind;
use Pront\Engine\Algorithm;
use Pront\Engine\InvalidReplayToken;
use Pront\Engine\ReplayToken;
use Pront\Version;

final class ReplayTokenTest extends TestCase
{
    /**
     * @return iterable<string, array{ReplayToken}>
     */
    public static function tokens(): iterable
    {
        yield 'fresh trial' => [new ReplayToken('App\Price::add', 42, 1, null)];
        yield 'negative seed, late k' => [new ReplayToken('A::m', PHP_INT_MIN, 1000, null)];
        yield 'recorded buffer, every kind' => [new ReplayToken('App\Ünïcode::m', -7, 3, array_map(
            static fn(DrawKind $kind): Draw => new Draw($kind, [PHP_INT_MAX, 0, 1]),
            DrawKind::cases(),
        ))];
        yield 'empty buffer' => [new ReplayToken('A::m', 0, 2, [])];
    }

    #[DataProvider('tokens')]
    public function testATokenRoundTripsAndNeedsNoShellQuoting(ReplayToken $token): void
    {
        $text = $token->toString();

        self::assertMatchesRegularExpression('/\Apront:[A-Za-z0-9_-]+\z/', $text);
        self::assertEquals($token, ReplayToken::parse($text));
        self::assertEquals($token, ReplayToken::parse("  {$text}\n"));
        self::assertSame(Version::ID, $token->version);
        self::assertSame(Algorithm::ID, $token->algorithm);
    }

    private static function encode(array $data): string
    {
        return ReplayToken::PREFIX . rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        $valid = ['v' => Version::ID, 'a' => Algorithm::ID, 't' => 'A::m', 's' => 1, 'k' => 1, 'b' => null];
        yield 'no prefix' => ['abc', 'A replay token starts with "pront:"'];
        yield 'not base64' => ['pront:***', 'The replay token is damaged'];
        yield 'not JSON' => [ReplayToken::PREFIX . base64_encode('nope'), 'The replay token is damaged'];
        yield 'k is 0' => [self::encode(['k' => 0] + $valid), 'The replay token is damaged'];
        yield 'seed is a string' => [self::encode(['s' => '1'] + $valid), 'The replay token is damaged'];
        yield 'buffer missing' => [self::encode(array_diff_key($valid, ['b' => true])), 'The replay token is damaged'];
        yield 'unknown draw kind' => [self::encode(['b' => [['Bogus', [0]]]] + $valid), 'a draw is not a [kind, slots] pair'];
        yield 'negative slot' => [self::encode(['b' => [['Int', [-1, 0]]]] + $valid), 'a slot is not a non-negative int'];
        yield 'other version' => [self::encode(['v' => '9.9.9'] + $valid), 'made by Pront 9.9.9; this is Pront ' . Version::ID];
        yield 'other algorithm' => [self::encode(['a' => 'pront-0'] + $valid), 'uses algorithm pront-0; this Pront uses ' . Algorithm::ID];
    }

    #[DataProvider('invalid')]
    public function testAnInvalidTokenIsRejectedWithItsReason(string $text, string $message): void
    {
        $this->expectException(InvalidReplayToken::class);
        $this->expectExceptionMessage($message);
        ReplayToken::parse($text);
    }
}
