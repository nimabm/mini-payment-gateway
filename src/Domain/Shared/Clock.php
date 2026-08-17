<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use DateTimeImmutable;

/**
 * Time is an injected dependency, never a global. Everything the domain
 * produces is UTC; rendering in Tehran time or in the Jalali calendar happens
 * only at the presentation edge.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
