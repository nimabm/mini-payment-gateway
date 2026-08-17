<?php

declare(strict_types=1);

namespace App\Domain\Webhook;

use App\Domain\Merchant\MerchantId;
use App\Domain\Payment\PaymentId;
use DateTimeImmutable;

/**
 * A server-to-server notification owed to a merchant.
 *
 * Merchants must never depend on the payer's browser reaching their callback
 * URL — browsers get closed on the bank's page every day. This queue is the
 * reliable channel, retried on a fixed schedule until the merchant answers 2xx.
 */
final class WebhookDelivery
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly WebhookDeliveryId $id,
        public readonly MerchantId $merchantId,
        public readonly PaymentId $paymentId,
        public readonly string $event,
        public readonly string $url,
        public readonly array $payload,
        private WebhookStatus $status,
        private int $attempts,
        private ?DateTimeImmutable $nextAttemptAt,
        private ?int $lastResponseCode,
        private ?string $lastError,
        public readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $deliveredAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function queue(
        MerchantId $merchantId,
        PaymentId $paymentId,
        string $event,
        string $url,
        array $payload,
        DateTimeImmutable $now,
    ): self {
        return new self(
            WebhookDeliveryId::generate(),
            $merchantId,
            $paymentId,
            $event,
            $url,
            $payload,
            WebhookStatus::Pending,
            0,
            $now,
            null,
            null,
            $now,
        );
    }

    public function status(): WebhookStatus
    {
        return $this->status;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function nextAttemptAt(): ?DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function lastResponseCode(): ?int
    {
        return $this->lastResponseCode;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function deliveredAt(): ?DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function markDelivered(int $responseCode, DateTimeImmutable $now): void
    {
        $this->attempts++;
        $this->status = WebhookStatus::Delivered;
        $this->lastResponseCode = $responseCode;
        $this->lastError = null;
        $this->nextAttemptAt = null;
        $this->deliveredAt = $now;
    }

    /**
     * @param DateTimeImmutable|null $retryAt Null exhausts the retry schedule.
     */
    public function markFailed(
        ?int $responseCode,
        string $error,
        ?DateTimeImmutable $retryAt,
    ): void {
        $this->attempts++;
        $this->lastResponseCode = $responseCode;
        $this->lastError = $error;

        if ($retryAt === null) {
            $this->status = WebhookStatus::Exhausted;
            $this->nextAttemptAt = null;

            return;
        }

        $this->status = WebhookStatus::Pending;
        $this->nextAttemptAt = $retryAt;
    }

    /** Puts an exhausted delivery back in the queue, from the admin panel. */
    public function requeue(DateTimeImmutable $now): void
    {
        $this->status = WebhookStatus::Pending;
        $this->nextAttemptAt = $now;
    }
}
