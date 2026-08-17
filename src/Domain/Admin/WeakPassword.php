<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use App\Domain\Shared\DomainException;

final class WeakPassword extends DomainException
{
    public static function tooShort(int $minimum): self
    {
        return new self(sprintf('A password must be at least %d characters long.', $minimum));
    }

    public function errorCode(): string
    {
        return 'weak_password';
    }
}
