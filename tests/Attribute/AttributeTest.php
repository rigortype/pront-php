<?php

declare(strict_types=1);

namespace Pront\Tests\Attribute;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pront\Attribute\Ensures;
use Pront\Attribute\Verify;

final class AttributeTest extends TestCase
{
    public static function positive(int $return): bool
    {
        return $return > 0;
    }

    public function instance(int $return): bool
    {
        return $return > 0;
    }

    private static function hidden(int $return): bool
    {
        return $return > 0;
    }

    public function testVerifyDefaults(): void
    {
        $verify = new Verify();

        self::assertSame(100, $verify->iterations);
        self::assertNull($verify->seed);
        self::assertSame(1, (new Verify(iterations: 1))->iterations);
        self::assertSame(-3, (new Verify(seed: -3))->seed);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function badIterations(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('badIterations')]
    public function testVerifyRejectsIterationsBelowOne(int $iterations): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("iterations must be at least 1, {$iterations} given");
        new Verify($iterations);
    }

    public function testEnsuresKeepsAClosure(): void
    {
        $closure = static fn(int $return): bool => $return > 0;

        self::assertSame($closure, (new Ensures($closure))->condition);
    }

    public function testEnsuresTurnsAStaticMethodReferenceIntoAClosure(): void
    {
        $condition = (new Ensures([self::class, 'positive']))->condition;

        self::assertTrue($condition(1));
        self::assertFalse($condition(0));
        self::assertSame('positive', (new \ReflectionFunction($condition))->getName());
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function badReferences(): iterable
    {
        yield 'missing method' => [[self::class, 'nope'], 'AttributeTest::nope() does not exist'];
        yield 'missing class' => [['No\\Such\\Checks', 'x'], 'No\\Such\\Checks::x() does not exist'];
        yield 'instance method' => [[self::class, 'instance'], 'is not a public static method'];
        yield 'private method' => [[self::class, 'hidden'], 'is not a public static method'];
        yield 'one element' => [[self::class], 'must be [Class::class, \'method\']'];
        yield 'keyed' => [['class' => self::class, 'method' => 'positive'], 'must be [Class::class, \'method\']'];
        yield 'not strings' => [[1, 2], 'must be [Class::class, \'method\']'];
    }

    /**
     * @param array<mixed> $reference
     */
    #[DataProvider('badReferences')]
    public function testEnsuresRejectsBadReferences(array $reference, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new Ensures($reference);
    }
}
