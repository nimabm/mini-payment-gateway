<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Shared\DomainException;

final class RefundExceedsPaidAmount extends DomainException
{
    public static function for(PaymentId $id): self
    {
        return new self(sprintf(
            'Refunding payment %s would return more than was collected.',
            $id->value,
        ));
    }

    public function errorCode(): string
    {
        return 'refund_exceeds_paid_amount';
    }
}
