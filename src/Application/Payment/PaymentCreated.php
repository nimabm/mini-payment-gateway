<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Domain\Payment\Payment;

final readonly class PaymentCreated
{
    public function __construct(
        public Payment $payment,
        public string $checkoutUrl,
        /** True when an idempotency key returned an earlier payment. */
        public bool $replayed,
    ) {
    }
}
