<?php

declare(strict_types=1);

namespace App\Application\Reporting;

/**
 * Headline numbers for a period.
 *
 * Amounts are minor units grouped by currency, because summing Rial and Dollar
 * into one number would be a lie.
 */
final readonly class Summary
{
    /**
     * @param array<string, int> $paidVolumeByCurrency
     * @param array<string, int> $refundedVolumeByCurrency
     */
    public function __construct(
        public int $total,
        public int $paid,
        public int $failed,
        public int $open,
        public array $paidVolumeByCurrency = [],
        public array $refundedVolumeByCurrency = [],
    ) {
    }

    public static function empty(): self
    {
        return new self(0, 0, 0, 0);
    }

    /**
     * Share of finished payments that succeeded. Open payments are excluded:
     * counting a payer who is still on the bank's page as a loss would make the
     * rate swing wildly during busy periods.
     */
    public function successRate(): float
    {
        $settled = $this->paid + $this->failed;

        return $settled === 0 ? 0.0 : round($this->paid / $settled * 100, 2);
    }

    public function averagePaidAmount(string $currency): int
    {
        $volume = $this->paidVolumeByCurrency[$currency] ?? 0;

        return $this->paid === 0 ? 0 : intdiv($volume, $this->paid);
    }
}
