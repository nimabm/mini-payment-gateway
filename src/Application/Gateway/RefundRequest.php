<?php

declare(strict_types=1);

namespace App\Application\Gateway;

use App\Domain\Gateway\GatewayConfig;
use App\Domain\Shared\Money;

final readonly class RefundRequest
{
    public function __construct(
        public GatewayConfig $gateway,
        public string $reference,
        public ?string $transactionId,
        public Money $amount,
        public ?string $reason = null,
    ) {
    }
}
