<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Domain\Merchant\MerchantId;
use App\Domain\Shared\Currency;

/**
 * The merchant's request to start collecting money.
 */
final readonly class CreatePaymentCommand
{
    public function __construct(
        public MerchantId $merchantId,
        public int $amount,
        public Currency $currency,
        public string $orderId,
        public string $callbackUrl,
        public ?string $description = null,
        public ?string $payerName = null,
        public ?string $payerEmail = null,
        public ?string $payerMobile = null,
        public ?string $idempotencyKey = null,
        public ?string $preferredGateway = null,
    ) {
    }
}
