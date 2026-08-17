<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Shared\UrlBuilder;
use App\Domain\Gateway\GatewayId;
use App\Domain\Merchant\Merchant;
use App\Domain\Merchant\MerchantRepository;
use App\Domain\Payment\Payer;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentRepository;
use App\Domain\Shared\Clock;
use App\Domain\Shared\Money;

/**
 * Creates a payment and hands the merchant the URL to send the payer to.
 *
 * Nothing is sent to any PSP here. The bank is only contacted when the payer
 * actually arrives at the checkout URL, which keeps abandoned carts from
 * filling the PSP with dead transactions.
 */
final readonly class CreatePaymentHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private MerchantRepository $merchants,
        private Clock $clock,
        private UrlBuilder $urls,
        private int $ttlMinutes,
    ) {
    }

    public function handle(CreatePaymentCommand $command): PaymentCreated
    {
        $merchant = $this->merchants->find($command->merchantId);

        if ($merchant === null || !$merchant->status()->canCreatePayments()) {
            throw MerchantNotActive::forMerchant($command->merchantId);
        }

        // Replaying the same idempotency key must never charge twice, so an
        // existing payment is returned verbatim.
        if ($command->idempotencyKey !== null) {
            $existing = $this->payments->findByIdempotencyKey(
                $command->merchantId,
                $command->idempotencyKey,
            );

            if ($existing !== null) {
                return $this->describe($existing, replayed: true);
            }
        }

        $this->assertCallbackAllowed($merchant, $command->callbackUrl);

        if ($this->payments->findByOrderId($command->merchantId, $command->orderId) !== null) {
            throw DuplicateOrderId::forOrder($command->orderId);
        }

        $now = $this->clock->now();

        $payment = Payment::create(
            merchantId: $command->merchantId,
            orderId: $command->orderId,
            amount: Money::of($command->amount, $command->currency),
            callbackUrl: $command->callbackUrl,
            now: $now,
            expiresAt: $now->modify(sprintf('+%d minutes', $this->ttlMinutes)),
            description: $command->description,
            payer: new Payer($command->payerName, $command->payerEmail, $command->payerMobile),
            idempotencyKey: $command->idempotencyKey,
            preferredGatewayId: $command->preferredGateway === null
                ? null
                : GatewayId::fromString($command->preferredGateway),
        );

        $this->payments->save($payment);

        return $this->describe($payment, replayed: false);
    }

    private function assertCallbackAllowed(Merchant $merchant, string $url): void
    {
        if (!$merchant->allowsCallbackTo($url)) {
            throw CallbackUrlNotAllowed::forUrl($url);
        }
    }

    private function describe(Payment $payment, bool $replayed): PaymentCreated
    {
        return new PaymentCreated(
            $payment,
            $this->urls->checkout($payment->id),
            $replayed,
        );
    }
}
