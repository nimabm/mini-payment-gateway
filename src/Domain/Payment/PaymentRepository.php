<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Merchant\MerchantId;
use DateTimeImmutable;

interface PaymentRepository
{
    /** Persists the payment and all of its attempts atomically. */
    public function save(Payment $payment): void;

    public function find(PaymentId $id): ?Payment;

    public function findByOrderId(MerchantId $merchantId, string $orderId): ?Payment;

    /**
     * Used to make payment creation idempotent: the same key from the same
     * merchant must always return the same payment.
     */
    public function findByIdempotencyKey(MerchantId $merchantId, string $key): ?Payment;

    /**
     * Payments the payer returned from but which were never confirmed. These
     * are the ones reconciliation chases.
     *
     * @return list<Payment>
     */
    public function findAwaitingVerification(DateTimeImmutable $olderThan, int $limit = 100): array;

    /**
     * Open payments whose expiry has passed.
     *
     * @return list<Payment>
     */
    public function findExpired(DateTimeImmutable $now, int $limit = 100): array;
}
