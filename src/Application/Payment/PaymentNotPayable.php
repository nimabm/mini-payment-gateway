<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Domain\Payment\PaymentId;
use App\Domain\Payment\PaymentStatus;
use App\Domain\Shared\DomainException;

final class PaymentNotPayable extends DomainException
{
    public static function forPayment(PaymentId $id, PaymentStatus $status): self
    {
        return new self(sprintf(
            'Payment %s is "%s" and can no longer be paid.',
            $id->value,
            $status->value,
        ));
    }

    public function errorCode(): string
    {
        return 'payment_not_payable';
    }
}
