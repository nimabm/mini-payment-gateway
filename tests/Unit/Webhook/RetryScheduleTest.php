<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Application\Webhook\RetrySchedule;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RetrySchedule::class)]
final class RetryScheduleTest extends TestCase
{
    #[Test]
    public function it_walks_the_configured_delays(): void
    {
        $schedule = RetrySchedule::fromString('1,5,30');
        $now = new DateTimeImmutable('2024-08-16 10:00:00', new DateTimeZone('UTC'));

        self::assertSame('10:01', $schedule->nextAttemptAfter(0, $now)?->format('H:i'));
        self::assertSame('10:05', $schedule->nextAttemptAfter(1, $now)?->format('H:i'));
        self::assertSame('10:30', $schedule->nextAttemptAfter(2, $now)?->format('H:i'));
    }

    #[Test]
    public function it_gives_up_once_the_schedule_is_exhausted(): void
    {
        $schedule = RetrySchedule::fromString('1,5,30');
        $now = new DateTimeImmutable('2024-08-16 10:00:00', new DateTimeZone('UTC'));

        self::assertNull($schedule->nextAttemptAfter(3, $now));
        self::assertNull($schedule->nextAttemptAfter(99, $now));
        self::assertSame(3, $schedule->maxAttempts());
    }

    #[Test]
    public function it_tolerates_whitespace_and_empty_entries(): void
    {
        self::assertSame(3, RetrySchedule::fromString(' 1, 5 ,30, ')->maxAttempts());
    }
}
