<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use RuntimeException;
use SensitiveParameter;

/**
 * Authenticated encryption for PSP credentials at rest.
 *
 * A stolen database file is not enough to charge cards on your merchant
 * accounts: the key lives in the environment, not in the database. XChaCha20-
 * Poly1305 also authenticates, so a tampered ciphertext fails loudly rather
 * than decrypting to garbage.
 */
final readonly class CredentialEncryptor
{
    private string $key;

    public function __construct(#[SensitiveParameter] string $base64Key)
    {
        $key = base64_decode($base64Key, true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(sprintf(
                'APP_KEY must be %d random bytes, base64 encoded. Generate one with: openssl rand -base64 32',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            ));
        }

        $this->key = $key;
    }

    /**
     * @param array<string, string> $credentials
     */
    public function encrypt(array $credentials): string
    {
        if ($credentials === []) {
            return '';
        }

        $plaintext = json_encode($credentials, JSON_THROW_ON_ERROR);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        sodium_memzero($plaintext);

        return base64_encode($nonce . $ciphertext);
    }

    /**
     * @return array<string, string>
     */
    public function decrypt(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        $decoded = base64_decode($payload, true);

        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Stored gateway credentials are malformed.');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext === false) {
            throw new RuntimeException(
                'Gateway credentials could not be decrypted. Has APP_KEY changed?',
            );
        }

        /** @var array<string, string> $credentials */
        $credentials = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);

        sodium_memzero($plaintext);

        return $credentials;
    }
}
