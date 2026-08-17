<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Gateway\GatewayId;
use App\Domain\Merchant\MerchantId;
use App\Domain\Shared\Money;
use DateTimeImmutable;

/**
 * The aggregate root of the whole system.
 *
 * Every rule about what may happen to money lives here, expressed as guarded
 * state transitions. Nothing outside this class is allowed to assign a status.
 */
final class Payment
{
    /** @var list<PaymentAttempt> */
    private array $attempts;

    /**
     * @param list<PaymentAttempt> $attempts
     */
    public function __construct(
        public readonly PaymentId $id,
        public readonly MerchantId $merchantId,
        public readonly string $orderId,
        public readonly Money $amount,
        public readonly ?string $description,
        public readonly string $callbackUrl,
        public readonly Payer $payer,
        public readonly ?string $idempotencyKey,
        private PaymentStatus $status,
        private Money $refundedAmount,
        private ?GatewayId $preferredGatewayId,
        private ?string $failureReason,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $paidAt = null,
        private ?DateTimeImmutable $updatedAt = null,
        array $attempts = [],
    ) {
        $this->attempts = $attempts;
    }

    public static function create(
        MerchantId $merchantId,
        string $orderId,
        Money $amount,
        string $callbackUrl,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
        ?string $description = null,
        ?Payer $payer = null,
        ?string $idempotencyKey = null,
        ?GatewayId $preferredGatewayId = null,
    ): self {
        return new self(
            PaymentId::generate(),
            $merchantId,
            $orderId,
            $amount,
            $description,
            $callbackUrl,
            $payer ?? Payer::anonymous(),
            $idempotencyKey,
            PaymentStatus::Created,
            Money::zero($amount->currency),
            $preferredGatewayId,
            null,
            $now,
            $expiresAt,
            null,
            $now,
        );
    }

    public function status(): PaymentStatus
    {
        return $this->status;
    }

    public function refundedAmount(): Money
    {
        return $this->refundedAmount;
    }

    public function preferredGatewayId(): ?GatewayId
    {
        return $this->preferredGatewayId;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    public function paidAt(): ?DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return list<PaymentAttempt> */
    public function attempts(): array
    {
        return $this->attempts;
    }

    public function attemptCount(): int
    {
        return count($this->attempts);
    }

    /** The attempt currently carrying the payment, if any. */
    public function currentAttempt(): ?PaymentAttempt
    {
        return $this->attempts === [] ? null : $this->attempts[count($this->attempts) - 1];
    }

    public function successfulAttempt(): ?PaymentAttempt
    {
        foreach ($this->attempts as $attempt) {
            if ($attempt->status() === AttemptStatus::Succeeded) {
                return $attempt;
            }
        }

        return null;
    }

    public function attemptByReference(string $reference): ?PaymentAttempt
    {
        foreach ($this->attempts as $attempt) {
            if ($attempt->reference() === $reference) {
                return $attempt;
            }
        }

        return null;
    }

    public function hasExpired(DateTimeImmutable $now): bool
    {
        return $now > $this->expiresAt;
    }

    /**
     * Records that a gateway accepted the payment and gave us a reference to
     * send the payer to.
     *
     * @param array<string, mixed> $requestPayload
     * @param array<string, mixed> $responsePayload
     */
    public function attachAttempt(
        GatewayId $gatewayId,
        string $reference,
        DateTimeImmutable $now,
        array $requestPayload = [],
        array $responsePayload = [],
    ): PaymentAttempt {
        $this->transitionTo(PaymentStatus::Pending, $now);

        $attempt = PaymentAttempt::start(
            $this->id,
            $gatewayId,
            $this->nextSequence(),
            $reference,
            $now,
            $requestPayload,
            $responsePayload,
        );

        $this->attempts[] = $attempt;

        return $attempt;
    }

    /**
     * Records a gateway that refused the payment outright. The payment itself
     * stays where it is so another gateway can still be tried.
     *
     * @param array<string, mixed> $requestPayload
     * @param array<string, mixed> $responsePayload
     */
    public function recordRejectedAttempt(
        GatewayId $gatewayId,
        string $failureCode,
        string $failureMessage,
        DateTimeImmutable $now,
        array $requestPayload = [],
        array $responsePayload = [],
    ): PaymentAttempt {
        $attempt = PaymentAttempt::failedImmediately(
            $this->id,
            $gatewayId,
            $this->nextSequence(),
            $failureCode,
            $failureMessage,
            $now,
            $requestPayload,
            $responsePayload,
        );

        $this->attempts[] = $attempt;
        $this->updatedAt = $now;

        return $attempt;
    }

    /**
     * The payer has come back from the PSP. The money may or may not have
     * moved; only verification will tell.
     */
    public function markAwaitingVerification(DateTimeImmutable $now): void
    {
        $this->currentAttempt()?->markReturned();
        $this->transitionTo(PaymentStatus::AwaitingVerification, $now);
    }

    /**
     * @param array<string, mixed> $responsePayload
     */
    public function markPaid(
        string $transactionId,
        DateTimeImmutable $now,
        ?string $cardPan = null,
        ?int $fee = null,
        array $responsePayload = [],
    ): void {
        $this->transitionTo(PaymentStatus::Paid, $now);
        $this->paidAt = $now;
        $this->failureReason = null;

        $this->currentAttempt()?->markSucceeded($transactionId, $cardPan, $fee, $now, $responsePayload);
    }

    /**
     * @param array<string, mixed> $responsePayload
     */
    public function fail(
        string $code,
        string $message,
        DateTimeImmutable $now,
        array $responsePayload = [],
    ): void {
        $this->transitionTo(PaymentStatus::Failed, $now);
        $this->failureReason = $message;

        $this->currentAttempt()?->markFailed($code, $message, $now, $responsePayload);
    }

    public function cancel(DateTimeImmutable $now, string $reason = 'Canceled by the payer.'): void
    {
        $this->transitionTo(PaymentStatus::Canceled, $now);
        $this->failureReason = $reason;

        $this->currentAttempt()?->markFailed('canceled', $reason, $now);
    }

    public function expire(DateTimeImmutable $now): void
    {
        $this->transitionTo(PaymentStatus::Expired, $now);
        $this->failureReason = 'The payment expired before it was completed.';
    }

    public function refund(Money $amount, DateTimeImmutable $now): void
    {
        $newTotal = $this->refundedAmount->add($amount);

        if ($newTotal->isGreaterThan($this->amount)) {
            throw RefundExceedsPaidAmount::for($this->id);
        }

        $target = $newTotal->equals($this->amount)
            ? PaymentStatus::Refunded
            : PaymentStatus::PartiallyRefunded;

        $this->transitionTo($target, $now);
        $this->refundedAmount = $newTotal;
    }

    public function refundableAmount(): Money
    {
        if (!$this->status->isSuccessful()) {
            return Money::zero($this->amount->currency);
        }

        return $this->amount->subtract($this->refundedAmount);
    }

    private function transitionTo(PaymentStatus $target, DateTimeImmutable $now): void
    {
        // Re-entering the same state is a no-op rather than an error: PSP
        // callbacks are routinely delivered more than once.
        if ($this->status === $target) {
            $this->updatedAt = $now;

            return;
        }

        if (!$this->status->canTransitionTo($target)) {
            throw InvalidPaymentTransition::between($this->status, $target);
        }

        $this->status = $target;
        $this->updatedAt = $now;
    }

    private function nextSequence(): int
    {
        return count($this->attempts) + 1;
    }
}
