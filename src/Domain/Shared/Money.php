<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;

/**
 * An exact monetary amount.
 *
 * The amount is always an integer in the currency's minor unit (Rial, Toman,
 * cent). Floating point never touches money in this codebase.
 */
final readonly class Money
{
    private function __construct(
        public int $amount,
        public Currency $currency,
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException('A monetary amount cannot be negative.');
        }
    }

    public static function of(int $amount, Currency $currency): self
    {
        return new self($amount, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        if ($other->amount > $this->amount) {
            throw new InvalidArgumentException('Subtraction would produce a negative amount.');
        }

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount < $other->amount;
    }

    /**
     * Converts between Rial and Toman, the one conversion that is a fixed rate
     * rather than a market rate. Any other pair is rejected: cross-currency
     * conversion is a business decision that does not belong in a value object.
     */
    public function convertTo(Currency $target): self
    {
        if ($target === $this->currency) {
            return $this;
        }

        return match (true) {
            $this->currency === Currency::IRR && $target === Currency::IRT
                => new self(intdiv($this->amount, 10), $target),
            $this->currency === Currency::IRT && $target === Currency::IRR
                => new self($this->amount * 10, $target),
            default => throw new InvalidArgumentException(sprintf(
                'No fixed conversion exists from %s to %s.',
                $this->currency->value,
                $target->value,
            )),
        };
    }

    /**
     * Human readable amount, e.g. "12,500" for IRT or "19.99" for USD.
     */
    public function format(): string
    {
        $decimals = $this->currency->decimals();

        if ($decimals === 0) {
            return number_format($this->amount);
        }

        return number_format($this->amount / (10 ** $decimals), $decimals);
    }

    public function __toString(): string
    {
        return $this->format() . ' ' . $this->currency->value;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException(sprintf(
                'Cannot combine %s with %s.',
                $this->currency->value,
                $other->currency->value,
            ));
        }
    }
}
