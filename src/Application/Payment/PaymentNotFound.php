<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Domain\Payment\PaymentId;
use App\Domain\Shared\DomainException;

final class PaymentNotFound extends DomainException
{
    public static function withId(PaymentId $id): self
    {
        return new self(sprintf('Payment %s does not exist.', $id->value));
    }

    public function errorCode(): string
    {
        return 'payment_not_found';
    }
}
