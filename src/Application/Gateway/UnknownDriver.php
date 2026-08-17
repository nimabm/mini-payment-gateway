<?php

declare(strict_types=1);

namespace App\Application\Gateway;

use App\Domain\Gateway\DriverName;
use App\Domain\Shared\DomainException;

final class UnknownDriver extends DomainException
{
    public static function named(DriverName $name): self
    {
        return new self(sprintf('No driver named "%s" is registered.', $name->value));
    }

    public function errorCode(): string
    {
        return 'unknown_driver';
    }
}
