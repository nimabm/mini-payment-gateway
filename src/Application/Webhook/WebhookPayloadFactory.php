<?php

declare(strict_types=1);

namespace App\Application\Webhook;

use App\Domain\Payment\Payment;
use App\Domain\Shared\Clock;

/**
 * Builds the JSON body merchants receive.
 *
 * This shape is a published contract. Fields may be added; existing fields must
 * keep their meaning, because every merchant module in the wild parses them.
 */
final readonly class WebhookPayloadFactory
{
    public function __construct(private Clock $clock)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function forPayment(Payment $payment, string $event): array
    {
        $attempt = $payment->successfulAttempt() ?? $payment->currentAttempt();

        return [
            'event' => $event,
            'sent_at' => $this->clock->now()->format(DATE_ATOM),
            'data' => [
                'payment_id' => $payment->id->value,
                'order_id' => $payment->orderId,
                'status' => $payment->status()->value,
                'amount' => $payment->amount->amount,
                'currency' => $payment->amount->currency->value,
                'description' => $payment->description,
                'paid_at' => $payment->paidAt()?->format(DATE_ATOM),
                'created_at' => $payment->createdAt->format(DATE_ATOM),
                'transaction_id' => $attempt?->transactionId(),
                'card_pan' => $attempt?->cardPan(),
                'failure_reason' => $payment->failureReason(),
            ],
        ];
    }
}
