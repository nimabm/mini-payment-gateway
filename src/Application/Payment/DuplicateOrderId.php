<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Domain\Shared\DomainException;

final class DuplicateOrderId extends DomainException
{
    public static function forOrder(string $orderId): self
    {
        return new self(sprintf('A payment already exists for order "%s".', $orderId));
    }

    public function errorCode(): string
    {
        return 'duplicate_order_id';
    }
}
