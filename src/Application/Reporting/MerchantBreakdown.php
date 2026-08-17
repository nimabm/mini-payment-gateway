<?php

declare(strict_types=1);

namespace App\Application\Reporting;

final readonly class MerchantBreakdown
{
    public function __construct(
        public string $merchantId,
        public string $merchantName,
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

    public function averageBasket(): int
    {
        return $this->paid === 0 ? 0 : intdiv($this->paidVolume, $this->paid);
    }
}
