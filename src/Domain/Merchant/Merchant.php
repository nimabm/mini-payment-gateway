<?php

declare(strict_types=1);

namespace App\Domain\Merchant;

use App\Domain\Shared\Currency;
use DateTimeImmutable;

/**
 * A website that is allowed to take payments through this gateway.
 */
final class Merchant
{
    /**
     * @param list<string> $allowedCallbackHosts Hosts a payment may return the
     *        payer to. Empty means "any host", which is only sensible in
     *        development.
     * @param list<string> $ipAllowlist Source IPs allowed to call the API.
     *        Empty means "any IP".
     */
    public function __construct(
        public readonly MerchantId $id,
        private string $name,
        private readonly string $slug,
        private MerchantStatus $status,
        private Currency $defaultCurrency,
        private ?string $webhookUrl,
        private array $allowedCallbackHosts,
        private array $ipAllowlist,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param list<string> $allowedCallbackHosts
     * @param list<string> $ipAllowlist
     */
    public static function register(
        string $name,
        string $slug,
        Currency $defaultCurrency,
        DateTimeImmutable $now,
        ?string $webhookUrl = null,
        array $allowedCallbackHosts = [],
        array $ipAllowlist = [],
    ): self {
        return new self(
            MerchantId::generate(),
            $name,
            $slug,
            MerchantStatus::Active,
            $defaultCurrency,
            $webhookUrl,
            $allowedCallbackHosts,
            $ipAllowlist,
            $now,
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function status(): MerchantStatus
    {
        return $this->status;
    }

    public function defaultCurrency(): Currency
    {
        return $this->defaultCurrency;
    }

    public function webhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    /** @return list<string> */
    public function allowedCallbackHosts(): array
    {
        return $this->allowedCallbackHosts;
    }

    /** @return list<string> */
    public function ipAllowlist(): array
    {
        return $this->ipAllowlist;
    }

    /**
     * @param list<string> $allowedCallbackHosts
     * @param list<string> $ipAllowlist
     */
    public function update(
        string $name,
        Currency $defaultCurrency,
        ?string $webhookUrl,
        array $allowedCallbackHosts,
        array $ipAllowlist,
    ): void {
        $this->name = $name;
        $this->defaultCurrency = $defaultCurrency;
        $this->webhookUrl = $webhookUrl;
        $this->allowedCallbackHosts = $allowedCallbackHosts;
        $this->ipAllowlist = $ipAllowlist;
    }

    public function activate(): void
    {
        $this->status = MerchantStatus::Active;
    }

    public function suspend(): void
    {
        $this->status = MerchantStatus::Suspended;
    }

    /**
     * Open redirects are the classic way to turn a payment gateway into a
     * phishing tool, so the return URL is validated against an allowlist.
     */
    public function allowsCallbackTo(string $url): bool
    {
        if ($this->allowedCallbackHosts === []) {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);

        foreach ($this->allowedCallbackHosts as $allowed) {
            $allowed = strtolower(trim($allowed));

            if ($allowed === $host) {
                return true;
            }

            // A leading dot allows every subdomain: ".example.com".
            if (str_starts_with($allowed, '.') && str_ends_with($host, $allowed)) {
                return true;
            }
        }

        return false;
    }

    public function allowsRequestFrom(?string $ip): bool
    {
        if ($this->ipAllowlist === []) {
            return true;
        }

        return $ip !== null && in_array($ip, $this->ipAllowlist, true);
    }
}
