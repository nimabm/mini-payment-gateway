<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Domain\Payment\Payment;

/**
 * Turns a Payment into the JSON a merchant sees.
 *
 * Kept in one place so the create, fetch and verify endpoints can never drift
 * apart — merchants parse all three with the same code.
 */
final readonly class PaymentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(Payment $payment, ?string $checkoutUrl = null): array
    {
        $attempt = $payment->successfulAttempt() ?? $payment->currentAttempt();

        $data = [
            'id' => $payment->id->value,
            'order_id' => $payment->orderId,
            'status' => $payment->status()->value,
            'paid' => $payment->status()->isSuccessful(),
            'amount' => $payment->amount->amount,
            'currency' => $payment->amount->currency->value,
            'refunded_amount' => $payment->refundedAmount()->amount,
            'description' => $payment->description,
            'callback_url' => $payment->callbackUrl,
            'transaction_id' => $attempt?->transactionId(),
            'card_pan' => $attempt?->cardPan(),
            'failure_reason' => $payment->failureReason(),
            'attempts' => $payment->attemptCount(),
            'created_at' => $payment->createdAt->format(DATE_ATOM),
            'expires_at' => $payment->expiresAt->format(DATE_ATOM),
            'paid_at' => $payment->paidAt()?->format(DATE_ATOM),
        ];

        if ($checkoutUrl !== null) {
            $data['checkout_url'] = $checkoutUrl;
        }

        return $data;
    }
}
