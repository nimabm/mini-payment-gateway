<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use App\Domain\Payment\PaymentStatus;
use App\Domain\Shared\Money;
use DateTimeImmutable;

/**
 * A flat, denormalised row for the transaction table and CSV export.
 *
 * Reports read this instead of hydrating Payment aggregates: rendering ten
 * thousand rows through an aggregate would be slow and pointless, since nothing
 * here changes state.
 *
 * A plain DTO with no knowledge of where it came from — building one from a
 * database row is the repository's job, in the infrastructure layer.
 */
final readonly class TransactionRow
{
    public function __construct(
        public string $paymentId,
        public string $orderId,
        public string $merchantName,
        public string $merchantId,
        public ?string $gatewayLabel,
        public ?string $driver,
        public PaymentStatus $status,
        public Money $amount,
        public ?string $transactionId,
        public ?string $reference,
        public ?string $cardPan,
        public ?string $payerEmail,
        public ?string $payerMobile,
        public ?string $failureReason,
        public int $attempts,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $paidAt,
    ) {
    }
}
