<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use SensitiveParameter;

/**
 * Computes and checks the HMAC signature on API requests.
 *
 * The signed string is built from the parts an attacker would want to change:
 *
 *     METHOD \n PATH \n TIMESTAMP \n NONCE \n SHA256(body)
 *
 * Hashing the body rather than including it keeps the signed string small and
 * binary-safe. The timestamp bounds a replay window and the nonce closes it
 * completely; neither alone is enough.
 *
 * This class is intentionally free of framework types so the merchant-side SDK
 * in `examples/` can be a copy of the same twelve lines.
 */
final readonly class RequestSigner
{
    public const HEADER_KEY_ID = 'X-Gateway-Key';
    public const HEADER_TIMESTAMP = 'X-Gateway-Timestamp';
    public const HEADER_NONCE = 'X-Gateway-Nonce';
    public const HEADER_SIGNATURE = 'X-Gateway-Signature';

    public function sign(
        #[SensitiveParameter]
        string $secret,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body,
    ): string {
        return hash_hmac(
            'sha256',
            $this->canonicalString($method, $path, $timestamp, $nonce, $body),
            $secret,
        );
    }

    public function verify(
        #[SensitiveParameter]
        string $secret,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body,
        string $signature,
    ): bool {
        $expected = $this->sign($secret, $method, $path, $timestamp, $nonce, $body);

        return hash_equals($expected, $signature);
    }

    public function canonicalString(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body,
    ): string {
        return implode("\n", [
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }
}
