<?php

declare(strict_types=1);

namespace App\Application\Gateway;

use App\Domain\Gateway\DriverName;

/**
 * Resolves a stored driver name to its implementation.
 */
interface DriverRegistry
{
    public function get(DriverName $name): PaymentGatewayDriver;

    public function has(DriverName $name): bool;

    /** @return list<PaymentGatewayDriver> */
    public function all(): array;
}
