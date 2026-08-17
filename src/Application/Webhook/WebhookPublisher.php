<?php

declare(strict_types=1);

namespace App\Application\Webhook;

use App\Domain\Merchant\MerchantRepository;
use App\Domain\Payment\Payment;
use App\Domain\Shared\Clock;
use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookRepository;

/**
 * Queues the notifications a merchant is owed when a payment reaches a final
 * state. Queuing only — sending is the worker's job, so a slow merchant server
 * can never delay a payer's redirect.
 */
final readonly class WebhookPublisher
{
    public const EVENT_SUCCEEDED = 'payment.succeeded';
    public const EVENT_FAILED = 'payment.failed';

    public function __construct(
        private WebhookRepository $deliveries,
        private MerchantRepository $merchants,
        private WebhookPayloadFactory $payloads,
        private Clock $clock,
    ) {
    }

    public function publishPaymentSucceeded(Payment $payment): void
    {
        $this->publish($payment, self::EVENT_SUCCEEDED);
    }

    public function publishPaymentFailed(Payment $payment): void
    {
        $this->publish($payment, self::EVENT_FAILED);
    }

    private function publish(Payment $payment, string $event): void
    {
        $merchant = $this->merchants->find($payment->merchantId);
        $url = $merchant?->webhookUrl();

        if ($url === null || $url === '') {
            return;
        }

        $this->deliveries->save(WebhookDelivery::queue(
            $payment->merchantId,
            $payment->id,
            $event,
            $url,
            $this->payloads->forPayment($payment, $event),
            $this->clock->now(),
        ));
    }
}
