<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Shared\DomainException;

final class InvalidPaymentTransition extends DomainException
{
    public static function between(PaymentStatus $from, PaymentStatus $to): self
    {
        return new self(sprintf(
            'A payment cannot move from "%s" to "%s".',
            $from->value,
            $to->value,
        ));
    }

    public function errorCode(): string
    {
        return 'invalid_payment_state';
    }
}
