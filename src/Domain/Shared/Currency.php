<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Currencies the gateway can settle in.
 *
 * `IRT` (Toman) is not an ISO 4217 code, but Iranian PSPs and merchants use it
 * everywhere, so modelling it explicitly is safer than letting each driver
 * invent its own convention.
 */
enum Currency: string
{
    case IRR = 'IRR';
    case IRT = 'IRT';
    case USD = 'USD';
    case EUR = 'EUR';

    /**
     * Number of decimal places between the minor unit stored in the database
     * and the amount a human reads.
     */
    public function decimals(): int
    {
        return match ($this) {
            self::IRR, self::IRT => 0,
            self::USD, self::EUR => 2,
        };
    }

    public function isIranian(): bool
    {
        return $this === self::IRR || $this === self::IRT;
    }
}
