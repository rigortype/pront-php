<?php

declare(strict_types=1);

namespace Pront\Tests\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pront\Cli\Application;
use Pront\Shrinker\StopReason;

final class ApplicationTest extends TestCase
{
    /**
     * Every case, so a case added to the shrinker fails here rather than as an internal error in
     * the middle of a run.
     *
     * @return iterable<string, array{StopReason}>
     */
    public static function stopReasons(): iterable
    {
        foreach (StopReason::cases() as $reason) {
            yield $reason->name => [$reason];
        }
    }

    #[DataProvider('stopReasons')]
    public function testEveryStopReasonIsDescribed(StopReason $reason): void
    {
        $text = Application::stopReason($reason);

        self::assertNotSame('', $text);
        self::assertSame($reason === StopReason::Fixpoint, $text === 'a local minimum');
    }
}
