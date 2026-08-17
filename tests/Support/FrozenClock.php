<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Shared\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A clock the test controls.
 *
 * Every expiry, retry-schedule and reconciliation rule in this codebase is a
 * statement about time, and none of them can be tested honestly against a real
 * clock.
 */
final class FrozenClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $now = '2024-08-16 10:00:00')
    {
        $this->now = new DateTimeImmutable($now, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
