<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Gateway\GatewayId;
use App\Domain\Merchant\MerchantId;
use App\Domain\Payment\AttemptStatus;
use App\Domain\Payment\InvalidPaymentTransition;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatus;
use App\Domain\Payment\RefundExceedsPaidAmount;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Payment::class)]
#[CoversClass(PaymentStatus::class)]
final class PaymentTest extends TestCase
{
    #[Test]
    public function a_new_payment_starts_in_created(): void
    {
        $payment = $this->payment();

        self::assertSame(PaymentStatus::Created, $payment->status());
        self::assertSame(0, $payment->attemptCount());
    }

    #[Test]
    public function attaching_an_attempt_moves_it_to_pending(): void
    {
        $payment = $this->payment();
        $payment->attachAttempt(GatewayId::generate(), 'AUTH-1', $this->now());

        self::assertSame(PaymentStatus::Pending, $payment->status());
        self::assertSame('AUTH-1', $payment->currentAttempt()?->reference());
    }

    #[Test]
    public function it_walks_the_full_happy_path(): void
    {
        $payment = $this->payment();
        $payment->attachAttempt(GatewayId::generate(), 'AUTH-1', $this->now());
        $payment->markAwaitingVerification($this->now());
        $payment->markPaid('REF-9', $this->now(), '621986******1234', 0);

        self::assertSame(PaymentStatus::Paid, $payment->status());
        self::assertTrue($payment->status()->isSuccessful());
        self::assertSame('REF-9', $payment->successfulAttempt()?->transactionId());
        self::assertSame(AttemptStatus::Succeeded, $payment->currentAttempt()?->status());
    }

    #[Test]
    public function a_paid_payment_cannot_be_failed(): void
    {
        $payment = $this->payment();
        $payment->attachAttempt(GatewayId::generate(), 'AUTH-1', $this->now());
        $payment->markPaid('REF-9', $this->now());

        $this->expectException(InvalidPaymentTransition::class);

        $payment->fail('whatever', 'Should be impossible.', $this->now());
    }

    /**
     * PSPs redeliver callbacks. Re-entering the same state must be a no-op, not
     * an exception, or a duplicate callback would 500 the payer.
     */
    #[Test]
    public function re_entering_the_same_state_is_a_no_op(): void
    {
        $payment = $this->payment();
        $payment->attachAttempt(GatewayId::generate(), 'AUTH-1', $this->now());
        $payment->markAwaitingVerification($this->now());
        $payment->markAwaitingVerification($this->now());

        self::assertSame(PaymentStatus::AwaitingVerification, $payment->status());
    }

    /**
     * Failover: one gateway refusing must leave the payment open for the next.
     */
    #[Test]
    public function a_rejected_gateway_does_not_close_the_payment(): void
    {
        $payment = $this->payment();
        $payment->recordRejectedAttempt(GatewayId::generate(), 'declined', 'Nope.', $this->now());

        self::assertSame(PaymentStatus::Created, $payment->status());
        self::assertSame(1, $payment->attemptCount());

        $payment->attachAttempt(GatewayId::generate(), 'AUTH-2', $this->now());
        $payment->markPaid('REF-2', $this->now());

        self::assertSame(PaymentStatus::Paid, $payment->status());
        self::assertSame(2, $payment->attemptCount());
    }

    #[Test]
    public function a_partial_refund_leaves_the_payment_refundable(): void
    {
        $payment = $this->paidPayment();
        $payment->refund(Money::of(30_000, Currency::IRT), $this->now());

        self::assertSame(PaymentStatus::PartiallyRefunded, $payment->status());
        self::assertSame(70_000, $payment->refundableAmount()->amount);

        $payment->refund(Money::of(70_000, Currency::IRT), $this->now());

        self::assertSame(PaymentStatus::Refunded, $payment->status());
        self::assertTrue($payment->refundableAmount()->isZero());
    }

    #[Test]
    public function it_refuses_to_refund_more_than_was_collected(): void
    {
        $payment = $this->paidPayment();

        $this->expectException(RefundExceedsPaidAmount::class);

        $payment->refund(Money::of(100_001, Currency::IRT), $this->now());
    }

    #[Test]
    public function expiry_is_decided_against_the_supplied_time(): void
    {
        $payment = $this->payment();

        self::assertFalse($payment->hasExpired($this->now()));
        self::assertTrue($payment->hasExpired($this->now()->modify('+31 minutes')));
    }

    private function payment(): Payment
    {
        return Payment::create(
            merchantId: MerchantId::generate(),
            orderId: 'ORDER-1',
            amount: Money::of(100_000, Currency::IRT),
            callbackUrl: 'https://shop.example.com/return',
            now: $this->now(),
            expiresAt: $this->now()->modify('+30 minutes'),
        );
    }

    private function paidPayment(): Payment
    {
        $payment = $this->payment();
        $payment->attachAttempt(GatewayId::generate(), 'AUTH-1', $this->now());
        $payment->markPaid('REF-1', $this->now());

        return $payment;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2024-08-16 10:00:00', new DateTimeZone('UTC'));
    }
}
