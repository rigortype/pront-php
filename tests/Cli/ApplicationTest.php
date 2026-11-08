<?php

declare(strict_types=1);

namespace Pront\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Pront\Cli\Application;
use Pront\Shrinker\StopReason;

final class ApplicationTest extends TestCase
{
    /**
     * Every case is pinned, so a case added to the shrinker fails here rather than as an internal
     * error in the middle of a run, and no two reasons read alike.
     */
    public function testEveryStopReasonIsDescribed(): void
    {
        $expected = [
            'Fixpoint' => 'a local minimum',
            'Budget' => 'stopped by the evaluation budget, possibly not minimal',
            'NotReproduced' => 'not shrunk: replaying the failing input did not reproduce the failure, so it is the input of the failing trial',
            'Time' => 'stopped by the time limit, possibly not minimal',
        ];
        $actual = [];
        foreach (StopReason::cases() as $reason) {
            $actual[$reason->name] = Application::stopReason($reason);
        }

        self::assertSame($expected, $actual);
    }
}
