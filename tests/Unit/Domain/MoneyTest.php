<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Money::class)]
final class MoneyTest extends TestCase
{
    #[Test]
    public function it_rejects_negative_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(-1, Currency::IRT);
    }

    #[Test]
    public function it_refuses_to_mix_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(100, Currency::IRT)->add(Money::of(100, Currency::USD));
    }

    #[Test]
    public function it_refuses_to_subtract_below_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(100, Currency::IRT)->subtract(Money::of(101, Currency::IRT));
    }

    #[Test]
    public function it_converts_between_rial_and_toman(): void
    {
        $rial = Money::of(150_000, Currency::IRR);

        self::assertSame(15_000, $rial->convertTo(Currency::IRT)->amount);
        self::assertSame(150_000, $rial->convertTo(Currency::IRT)->convertTo(Currency::IRR)->amount);
    }

    #[Test]
    public function it_refuses_market_rate_conversions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(1000, Currency::IRT)->convertTo(Currency::USD);
    }

    #[Test]
    public function it_formats_according_to_the_currencys_decimals(): void
    {
        self::assertSame('12,500', Money::of(12_500, Currency::IRT)->format());
        self::assertSame('19.99', Money::of(1999, Currency::USD)->format());
    }
}
