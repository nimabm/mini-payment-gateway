<?php

declare(strict_types=1);

namespace App\Application\Reporting;

final readonly class GatewayBreakdown
{
    public function __construct(
        public string $gatewayId,
        public string $gatewayLabel,
        public string $driver,
        public bool $sandbox,
        public int $attempts,
        public int $succeeded,
        public int $failed,
        public int $paidVolume,
        public string $currency,
    ) {
    }

    public function successRate(): float
    {
        $settled = $this->succeeded + $this->failed;

        return $settled === 0 ? 0.0 : round($this->succeeded / $settled * 100, 2);
    }
}
