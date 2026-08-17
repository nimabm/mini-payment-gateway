<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Payment\Payment;
use SensitiveParameter;

/**
 * Signs the parameters appended to the merchant's return URL, and the webhook
 * body.
 *
 * Without this, a payer could edit `status=paid` into the URL they are
 * redirected to and a naive merchant module would mark the order as paid. The
 * signature makes the redirect self-verifying, while still leaving the API the
 * authoritative answer.
 */
final readonly class CallbackSigner
{
    public const HEADER_SIGNATURE = 'X-Gateway-Signature';
    public const HEADER_EVENT = 'X-Gateway-Event';

    /**
     * @return array<string, string> Query parameters, including the signature.
     */
    public function signRedirect(Payment $payment, #[SensitiveParameter] string $secret): array
    {
        $attempt = $payment->successfulAttempt() ?? $payment->currentAttempt();

        $parameters = [
            'payment_id' => $payment->id->value,
            'order_id' => $payment->orderId,
            'status' => $payment->status()->value,
            'amount' => (string) $payment->amount->amount,
            'currency' => $payment->amount->currency->value,
            'transaction_id' => $attempt?->transactionId() ?? '',
        ];

        $parameters['signature'] = $this->signParameters($parameters, $secret);

        return $parameters;
    }

    /**
     * @param array<string, string> $parameters
     */
    public function signParameters(array $parameters, #[SensitiveParameter] string $secret): string
    {
        unset($parameters['signature']);

        // Sorting by key makes the signature independent of parameter order,
        // which is what lets merchants verify it from a plain query string.
        ksort($parameters);

        $canonical = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * @param array<string, string> $parameters
     */
    public function verifyParameters(
        array $parameters,
        #[SensitiveParameter]
        string $secret,
        string $signature,
    ): bool {
        return hash_equals($this->signParameters($parameters, $secret), $signature);
    }

    public function signBody(string $body, #[SensitiveParameter] string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }
}
