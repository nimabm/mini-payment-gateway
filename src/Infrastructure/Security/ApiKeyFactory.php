<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

/**
 * Mints API credentials.
 *
 * Note the deliberate asymmetry with admin passwords, which are hashed one-way:
 * an API secret is a *shared* secret. The server has to recompute the request
 * signature with it, so it cannot be hashed. It is stored encrypted instead,
 * under APP_KEY, which lives in the environment rather than the database.
 */
final readonly class ApiKeyFactory
{
    private const KEY_PREFIX = 'pk_';
    private const SECRET_PREFIX = 'sk_';

    /**
     * @return array{keyId: string, secret: string}
     */
    public function create(): array
    {
        return [
            'keyId' => self::KEY_PREFIX . bin2hex(random_bytes(12)),
            'secret' => self::SECRET_PREFIX . bin2hex(random_bytes(32)),
        ];
    }
}
