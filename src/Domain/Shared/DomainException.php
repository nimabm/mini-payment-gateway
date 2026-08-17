<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use RuntimeException;

/**
 * Base class for every rule the domain refuses to break.
 *
 * The HTTP layer maps these to 4xx responses; anything else that escapes is a
 * bug and becomes a 500.
 */
abstract class DomainException extends RuntimeException
{
    /**
     * Stable, machine readable error code returned to API clients.
     */
    abstract public function errorCode(): string;
}
