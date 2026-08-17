<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use DateTimeImmutable;

/**
 * One configured PSP connection: which driver to use, with which credentials,
 * against which environment.
 *
 * The same driver may be configured several times — a live ZarinPal account
 * and a sandbox one, or two ZarinPal accounts for two different businesses.
 */
final class GatewayConfig
{
    /**
     * @param array<string, string> $credentials Driver specific secrets. Stored
     *        encrypted; only ever decrypted in memory.
     * @param list<Currency> $currencies Currencies this connection accepts.
     */
    public function __construct(
        public readonly GatewayId $id,
        public readonly DriverName $driver,
        private string $label,
        private array $credentials,
        private array $currencies,
        private bool $sandbox,
        private bool $enabled,
        private int $priority,
        private ?int $minAmount,
        private ?int $maxAmount,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<string, string> $credentials
     * @param list<Currency> $currencies
     */
    public static function configure(
        DriverName $driver,
        string $label,
        array $credentials,
        array $currencies,
        bool $sandbox,
        DateTimeImmutable $now,
        int $priority = 100,
    ): self {
        return new self(
            GatewayId::generate(),
            $driver,
            $label,
            $credentials,
            $currencies,
            $sandbox,
            false, // A freshly configured gateway is disabled until reviewed.
            $priority,
            null,
            null,
            $now,
        );
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return array<string, string> */
    public function credentials(): array
    {
        return $this->credentials;
    }

    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    /** @return list<Currency> */
    public function currencies(): array
    {
        return $this->currencies;
    }

    /**
     * Whether this connection talks to the PSP's test environment. Drivers read
     * this to pick their base URL, so flipping it in the admin panel is all it
     * takes to move a gateway between sandbox and live.
     */
    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** Lower numbers are tried first. */
    public function priority(): int
    {
        return $this->priority;
    }

    public function minAmount(): ?int
    {
        return $this->minAmount;
    }

    public function maxAmount(): ?int
    {
        return $this->maxAmount;
    }

    /**
     * @param array<string, string> $credentials
     * @param list<Currency> $currencies
     */
    public function reconfigure(
        string $label,
        array $credentials,
        array $currencies,
        bool $sandbox,
        int $priority,
        ?int $minAmount,
        ?int $maxAmount,
    ): void {
        $this->label = $label;
        $this->credentials = $credentials;
        $this->currencies = $currencies;
        $this->sandbox = $sandbox;
        $this->priority = $priority;
        $this->minAmount = $minAmount;
        $this->maxAmount = $maxAmount;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function setSandbox(bool $sandbox): void
    {
        $this->sandbox = $sandbox;
    }

    /**
     * Whether this connection is willing to handle the given amount right now.
     */
    public function accepts(Money $money): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if (!in_array($money->currency, $this->currencies, true)) {
            return false;
        }

        if ($this->minAmount !== null && $money->amount < $this->minAmount) {
            return false;
        }

        return $this->maxAmount === null || $money->amount <= $this->maxAmount;
    }
}
