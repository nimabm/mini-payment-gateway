<?php

declare(strict_types=1);

namespace App\Application\Gateway;

use App\Domain\Gateway\GatewayConfig;
use App\Domain\Payment\Payer;
use App\Domain\Payment\PaymentId;
use App\Domain\Shared\Money;

/**
 * Everything a driver needs to open a transaction with its PSP.
 */
final readonly class PurchaseRequest
{
    public function __construct(
        public GatewayConfig $gateway,
        public PaymentId $paymentId,
        public Money $amount,
        public string $callbackUrl,
        public ?string $description,
        public Payer $payer,
        public string $orderId,
    ) {
    }
}
