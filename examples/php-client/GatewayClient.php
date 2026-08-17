<?php

declare(strict_types=1);

namespace YourShop\Payments;

use RuntimeException;

/**
 * A complete client for the Mini Payment Gateway.
 *
 * Copy this file into your site. It has no dependencies beyond cURL and hides
 * the request signing so your shop code never deals with HMAC.
 *
 * @see docs/INTEGRATION.md
 */
final class GatewayClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $keyId,
        private readonly string $secret,
        private readonly int $timeout = 20,
    ) {
    }

    /**
     * Creates a payment. Redirect the customer to the returned `checkout_url`.
     *
     * @param int $amount In the currency's minor unit — Toman and Rial have no
     *        decimals, so 150000 IRT means 150,000 Toman.
     * @param array<string, mixed> $extra description, payer_email, payer_mobile…
     * @return array<string, mixed>
     */
    public function createPayment(
        int $amount,
        string $orderId,
        string $callbackUrl,
        string $currency = 'IRT',
        array $extra = [],
    ): array {
        // `$extra` is merged last so a caller can override any default,
        // including the idempotency key.
        return $this->request('POST', '/api/v1/payments', array_merge([
            'amount' => $amount,
            'currency' => $currency,
            'order_id' => $orderId,
            'callback_url' => $callbackUrl,
            // Deriving the key from the order means a retried request — a
            // double click, a timeout, a refreshed page — can never create a
            // second payment for the same order.
            'idempotency_key' => 'order-' . $orderId,
        ], $extra));
    }

    /**
     * The authoritative answer to "was this paid?". Call it before you fulfil
     * anything, whatever the browser redirect claimed.
     *
     * @return array<string, mixed>
     */
    public function getPayment(string $paymentId): array
    {
        return $this->request('GET', '/api/v1/payments/' . $paymentId);
    }

    /**
     * Forces a fresh check with the bank. Rarely needed.
     *
     * @return array<string, mixed>
     */
    public function verifyPayment(string $paymentId): array
    {
        return $this->request('POST', '/api/v1/payments/' . $paymentId . '/verify');
    }

    /**
     * Gateways this site may use, if you want to render your own picker.
     *
     * @return array<string, mixed>
     */
    public function gateways(): array
    {
        return $this->request('GET', '/api/v1/gateways');
    }

    /**
     * Verifies the signed parameters on the return URL.
     *
     * A valid signature proves the values were not edited in the address bar.
     * It does not replace calling {@see getPayment()}.
     *
     * @param array<string, string> $parameters Usually `$_GET`.
     */
    public function verifyRedirect(array $parameters): bool
    {
        $signature = (string) ($parameters['signature'] ?? '');

        if ($signature === '') {
            return false;
        }

        unset($parameters['signature']);
        ksort($parameters);

        $expected = hash_hmac(
            'sha256',
            http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
            $this->secret,
        );

        return hash_equals($expected, $signature);
    }

    /**
     * Verifies an incoming webhook.
     *
     * @param string $body The raw request body — `file_get_contents('php://input')`.
     *        Not the decoded array: re-encoding changes the bytes and breaks
     *        the signature.
     */
    public function verifyWebhook(string $body, string $signatureHeader): bool
    {
        return hash_equals(hash_hmac('sha256', $body, $this->secret), $signatureHeader);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $body = $payload === null
            ? ''
            : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        $canonical = implode("\n", [
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Gateway-Key: ' . $this->keyId,
            'X-Gateway-Timestamp: ' . $timestamp,
            'X-Gateway-Nonce: ' . $nonce,
            'X-Gateway-Signature: ' . hash_hmac('sha256', $canonical, $this->secret),
        ];

        $handle = curl_init(rtrim($this->baseUrl, '/') . $path);

        if ($handle === false) {
            throw new RuntimeException('Could not initialise cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        if ($body !== '') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);

        curl_close($handle);

        if ($response === false) {
            throw new GatewayException('The gateway is unreachable: ' . $error, 'gateway_unreachable', 0);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response, true) ?: [];

        if (isset($decoded['error'])) {
            /** @var array{code?: string, message?: string} $apiError */
            $apiError = $decoded['error'];

            throw new GatewayException(
                $apiError['message'] ?? 'The gateway returned an error.',
                $apiError['code'] ?? 'unknown_error',
                $status,
            );
        }

        /** @var array<string, mixed> $data */
        $data = $decoded['data'] ?? [];

        return $data;
    }
}
