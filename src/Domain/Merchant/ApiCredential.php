<?php

declare(strict_types=1);

namespace App\Domain\Merchant;

use DateTimeImmutable;

/**
 * An API key pair belonging to a merchant.
 *
 * The public half (`keyId`) travels in a header and identifies the caller. The
 * secret half is shared: it is used to sign requests and never transmitted, but
 * both sides must know it, so it is stored encrypted rather than hashed. How
 * that encryption happens is the repository's business, not the domain's.
 *
 * A merchant may hold several live credentials at once, which is what makes
 * zero-downtime key rotation possible.
 */
final class ApiCredential
{
    public function __construct(
        public readonly ApiCredentialId $id,
        public readonly MerchantId $merchantId,
        public readonly string $keyId,
        public readonly string $secret,
        private string $label,
        public readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $lastUsedAt = null,
        private ?DateTimeImmutable $revokedAt = null,
    ) {
    }

    public static function issue(
        MerchantId $merchantId,
        string $keyId,
        string $secret,
        string $label,
        DateTimeImmutable $now,
    ): self {
        return new self(
            ApiCredentialId::generate(),
            $merchantId,
            $keyId,
            $secret,
            $label,
            $now,
        );
    }

    public function label(): string
    {
        return $this->label;
    }

    public function rename(string $label): void
    {
        $this->label = $label;
    }

    public function lastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isActive(): bool
    {
        return $this->revokedAt === null;
    }

    public function revoke(DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }

    public function markUsed(DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
    }

    /**
     * A safe fragment for the admin panel, e.g. "pk_1a2b…9f8e". The full key id
     * is not a secret, but showing it truncated keeps screenshots harmless.
     */
    public function maskedKeyId(): string
    {
        if (strlen($this->keyId) <= 12) {
            return $this->keyId;
        }

        return substr($this->keyId, 0, 8) . '…' . substr($this->keyId, -4);
    }
}
