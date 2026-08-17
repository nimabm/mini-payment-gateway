<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Domain\Payment\PaymentRepository;
use App\Domain\Shared\Clock;

/**
 * Closes payments the payer never came back to, so reports are not permanently
 * polluted by carts that were abandoned months ago.
 */
final readonly class ExpirePaymentsHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private Clock $clock,
    ) {
    }

    /**
     * @return int Number of payments expired.
     */
    public function handle(int $batchSize = 200): int
    {
        $now = $this->clock->now();
        $expired = $this->payments->findExpired($now, $batchSize);

        foreach ($expired as $payment) {
            $payment->expire($now);
            $this->payments->save($payment);
        }

        return count($expired);
    }
}
