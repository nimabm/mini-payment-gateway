<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Gateway\GatewayId;
use DateTimeImmutable;

/**
 * One attempt to collect a payment through one gateway.
 *
 * Failover means a single payment can have several attempts. Keeping them as
 * separate records — rather than overwriting fields on the payment — is what
 * makes "we tried ZarinPal, it timed out, the second gateway succeeded"
 * visible in the admin panel instead of lost.
 */
final class PaymentAttempt
{
    /**
     * @param array<string, mixed> $requestPayload  What we sent the PSP, secrets removed.
     * @param array<string, mixed> $responsePayload What the PSP sent back.
     */
    public function __construct(
        public readonly PaymentAttemptId $id,
        public readonly PaymentId $paymentId,
        public readonly GatewayId $gatewayId,
        public readonly int $sequence,
        private AttemptStatus $status,
        private ?string $reference,
        private ?string $transactionId,
        private ?string $cardPan,
        private ?int $fee,
        private ?string $failureCode,
        private ?string $failureMessage,
        private array $requestPayload,
        private array $responsePayload,
        public readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $completedAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $requestPayload
     * @param array<string, mixed> $responsePayload
     */
    public static function start(
        PaymentId $paymentId,
        GatewayId $gatewayId,
        int $sequence,
        string $reference,
        DateTimeImmutable $now,
        array $requestPayload = [],
        array $responsePayload = [],
    ): self {
        return new self(
            PaymentAttemptId::generate(),
            $paymentId,
            $gatewayId,
            $sequence,
            AttemptStatus::Started,
            $reference,
            null,
            null,
            null,
            null,
            null,
            $requestPayload,
            $responsePayload,
            $now,
        );
    }

    /**
     * @param array<string, mixed> $requestPayload
     * @param array<string, mixed> $responsePayload
     */
    public static function failedImmediately(
        PaymentId $paymentId,
        GatewayId $gatewayId,
        int $sequence,
        string $failureCode,
        string $failureMessage,
        DateTimeImmutable $now,
        array $requestPayload = [],
        array $responsePayload = [],
    ): self {
        return new self(
            PaymentAttemptId::generate(),
            $paymentId,
            $gatewayId,
            $sequence,
            AttemptStatus::Failed,
            null,
            null,
            null,
            null,
            $failureCode,
            $failureMessage,
            $requestPayload,
            $responsePayload,
            $now,
            $now,
        );
    }

    public function status(): AttemptStatus
    {
        return $this->status;
    }

    /** The PSP's handle for this attempt, e.g. ZarinPal's Authority. */
    public function reference(): ?string
    {
        return $this->reference;
    }

    /** The PSP's handle for the settled transaction, e.g. ZarinPal's RefID. */
    public function transactionId(): ?string
    {
        return $this->transactionId;
    }

    public function cardPan(): ?string
    {
        return $this->cardPan;
    }

    public function fee(): ?int
    {
        return $this->fee;
    }

    public function failureCode(): ?string
    {
        return $this->failureCode;
    }

    public function failureMessage(): ?string
    {
        return $this->failureMessage;
    }

    /** @return array<string, mixed> */
    public function requestPayload(): array
    {
        return $this->requestPayload;
    }

    /** @return array<string, mixed> */
    public function responsePayload(): array
    {
        return $this->responsePayload;
    }

    public function completedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function markReturned(): void
    {
        if ($this->status === AttemptStatus::Started) {
            $this->status = AttemptStatus::Returned;
        }
    }

    /**
     * @param array<string, mixed> $responsePayload
     */
    public function markSucceeded(
        string $transactionId,
        ?string $cardPan,
        ?int $fee,
        DateTimeImmutable $now,
        array $responsePayload = [],
    ): void {
        $this->status = AttemptStatus::Succeeded;
        $this->transactionId = $transactionId;
        $this->cardPan = $cardPan;
        $this->fee = $fee;
        $this->completedAt = $now;

        if ($responsePayload !== []) {
            $this->responsePayload = $responsePayload;
        }
    }

    /**
     * @param array<string, mixed> $responsePayload
     */
    public function markFailed(
        string $code,
        string $message,
        DateTimeImmutable $now,
        array $responsePayload = [],
    ): void {
        $this->status = AttemptStatus::Failed;
        $this->failureCode = $code;
        $this->failureMessage = $message;
        $this->completedAt = $now;

        if ($responsePayload !== []) {
            $this->responsePayload = $responsePayload;
        }
    }
}
