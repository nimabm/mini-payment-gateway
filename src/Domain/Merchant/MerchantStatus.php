<?php

declare(strict_types=1);

namespace App\Domain\Merchant;

enum MerchantStatus: string
{
    /** Fully operational. */
    case Active = 'active';

    /** Existing payments can still be verified, but no new ones are accepted. */
    case Suspended = 'suspended';

    public function canCreatePayments(): bool
    {
        return $this === self::Active;
    }
}
