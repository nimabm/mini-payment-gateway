<?php

declare(strict_types=1);

namespace App\Application\Shared;

use App\Domain\Gateway\GatewayId;
use App\Domain\Payment\PaymentId;

/**
 * Builds the absolute URLs this gateway hands out.
 *
 * PSPs store the callback URL server-side, so it must be absolute and stable —
 * deriving it from the incoming request would break the moment a payer arrives
 * over a different host or scheme.
 */
final readonly class UrlBuilder
{
    private string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** Where the merchant sends the payer to begin. */
    public function checkout(PaymentId $paymentId): string
    {
        return sprintf('%s/pay/%s', $this->baseUrl, $paymentId->value);
    }

    /** Where the PSP returns the payer to. */
    public function gatewayCallback(GatewayId $gatewayId, PaymentId $paymentId): string
    {
        return sprintf(
            '%s/callback/%s/%s',
            $this->baseUrl,
            $gatewayId->value,
            $paymentId->value,
        );
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }
}
