<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Domain\Payment\PaymentId;
use App\Domain\Shared\DomainException;

final class NoGatewayAvailable extends DomainException
{
    public static function forPayment(PaymentId $id): self
    {
        return new self(sprintf(
            'No enabled gateway can accept payment %s. Check the merchant\'s gateway assignments, '
                . 'the amount limits and the currency.',
            $id->value,
        ));
    }

    public function errorCode(): string
    {
        return 'no_gateway_available';
    }
}
