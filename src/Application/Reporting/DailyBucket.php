<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use DateTimeImmutable;

final readonly class DailyBucket
{
    public function __construct(
        public DateTimeImmutable $date,
        public int $total,
        public int $paid,
        public int $failed,
        public int $paidVolume,
        public string $currency,
    ) {
    }

    public function successRate(): float
    {
        $settled = $this->paid + $this->failed;

        return $settled === 0 ? 0.0 : round($this->paid / $settled * 100, 2);
    }
}
