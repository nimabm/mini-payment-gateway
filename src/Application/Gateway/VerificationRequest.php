<?php

declare(strict_types=1);

namespace App\Application\Gateway;

use App\Domain\Gateway\GatewayConfig;
use App\Domain\Shared\Money;

final readonly class VerificationRequest
{
    /**
     * @param array<string, string> $callbackParameters Query or form data the
     *        PSP sent the payer back with.
     */
    public function __construct(
        public GatewayConfig $gateway,
        public string $reference,
        public Money $amount,
        public array $callbackParameters = [],
    ) {
    }

    public function callbackParameter(string $name): ?string
    {
        return $this->callbackParameters[$name] ?? null;
    }
}
