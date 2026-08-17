<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Domain\Payment\Payment;

final readonly class CheckoutStarted
{
    public function __construct(
        public Payment $payment,
        public string $redirectUrl,
        /** True when the payer was returned to an attempt that was already open. */
        public bool $resumed,
    ) {
    }
}
