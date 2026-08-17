<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Domain\Shared\DomainException;

final class CallbackUrlNotAllowed extends DomainException
{
    public static function forUrl(string $url): self
    {
        return new self(sprintf(
            'The callback URL "%s" is not on this merchant\'s allowlist.',
            $url,
        ));
    }

    public function errorCode(): string
    {
        return 'callback_url_not_allowed';
    }
}
