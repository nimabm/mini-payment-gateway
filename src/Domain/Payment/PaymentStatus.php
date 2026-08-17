<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * The lifecycle of a payment.
 *
 *   Created ─▶ Pending ─▶ AwaitingVerification ─▶ Paid ─▶ Refunded
 *      │          │                 │              │      PartiallyRefunded
 *      ▼          ▼                 ▼              │
 *   Expired    Failed            Failed            │
 *              Canceled                            │
 *
 * `AwaitingVerification` is the state that matters most in practice: the payer
 * has been charged by the PSP but we have not yet confirmed it. Money exists in
 * limbo here, which is why reconciliation exists.
 */
enum PaymentStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case AwaitingVerification = 'awaiting_verification';
    case Paid = 'paid';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Created => [self::Pending, self::Failed, self::Canceled, self::Expired],
            self::Pending => [self::AwaitingVerification, self::Paid, self::Failed, self::Canceled, self::Expired],
            self::AwaitingVerification => [self::Paid, self::Failed],
            self::Paid => [self::Refunded, self::PartiallyRefunded],
            self::PartiallyRefunded => [self::Refunded, self::PartiallyRefunded],
            self::Failed, self::Canceled, self::Expired, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** No further state change is possible. */
    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** The merchant may consider the order paid. */
    public function isSuccessful(): bool
    {
        return $this === self::Paid
            || $this === self::Refunded
            || $this === self::PartiallyRefunded;
    }

    /** Still moving; a report should not count it as won or lost yet. */
    public function isOpen(): bool
    {
        return $this === self::Created
            || $this === self::Pending
            || $this === self::AwaitingVerification;
    }
}
