<?php

declare(strict_types=1);

namespace App\Application\Gateway;

use App\Domain\Gateway\GatewayConfig;
use App\Domain\Gateway\GatewayRepository;
use App\Domain\Payment\Payment;

/**
 * Decides which gateways a payment may be routed through, and in what order.
 *
 * The result is a list rather than a single choice, because the checkout
 * handler walks it: if the first PSP is down or rejects the request, the next
 * one is tried without the merchant or the payer ever noticing.
 */
final readonly class GatewayRouter
{
    public function __construct(private GatewayRepository $gateways)
    {
    }

    /**
     * @return list<GatewayConfig> Ordered by preference; empty if none can take
     *         this payment.
     */
    public function candidatesFor(Payment $payment): array
    {
        $assigned = $this->gateways->findAssignedTo($payment->merchantId);

        $eligible = array_values(array_filter(
            $assigned,
            static fn (GatewayConfig $gateway): bool => $gateway->accepts($payment->amount),
        ));

        // An explicit choice by the merchant wins, but only if that gateway is
        // actually able to take the payment — otherwise we silently fall back
        // rather than failing the checkout.
        $preferred = $payment->preferredGatewayId();

        if ($preferred !== null) {
            usort(
                $eligible,
                static fn (GatewayConfig $a, GatewayConfig $b): int
                    => ($b->id->equals($preferred) ? 1 : 0) <=> ($a->id->equals($preferred) ? 1 : 0),
            );
        }

        return $eligible;
    }

    /**
     * Gateways already tried for this payment, so the checkout handler never
     * offers the same failing PSP twice.
     *
     * @return list<GatewayConfig>
     */
    public function untriedCandidatesFor(Payment $payment): array
    {
        $tried = [];

        foreach ($payment->attempts() as $attempt) {
            $tried[$attempt->gatewayId->value] = true;
        }

        return array_values(array_filter(
            $this->candidatesFor($payment),
            static fn (GatewayConfig $gateway): bool => !isset($tried[$gateway->id->value]),
        ));
    }
}
