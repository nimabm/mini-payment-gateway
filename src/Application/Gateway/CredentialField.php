<?php

declare(strict_types=1);

namespace App\Application\Gateway;

/**
 * Describes one credential a driver needs, so the admin panel can render a
 * correct form for a driver it knows nothing about.
 */
final readonly class CredentialField
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $secret = true,
        public bool $required = true,
        public ?string $hint = null,
    ) {
    }
}
