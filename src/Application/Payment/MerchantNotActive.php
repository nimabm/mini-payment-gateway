<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Domain\Merchant\MerchantId;
use App\Domain\Shared\DomainException;

final class MerchantNotActive extends DomainException
{
    public static function forMerchant(MerchantId $id): self
    {
        return new self(sprintf('Merchant %s is suspended and cannot create payments.', $id->value));
    }

    public function errorCode(): string
    {
        return 'merchant_suspended';
    }
}
