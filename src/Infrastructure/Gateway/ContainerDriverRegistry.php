<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Application\Gateway\DriverRegistry;
use App\Application\Gateway\PaymentGatewayDriver;
use App\Application\Gateway\UnknownDriver;
use App\Domain\Gateway\DriverName;

/**
 * Resolves driver names against the drivers wired up in the container.
 *
 * Registering a new PSP is a one-line change in `config/drivers.php`; nothing
 * else in the application needs to know it happened.
 */
final class ContainerDriverRegistry implements DriverRegistry
{
    /** @var array<string, PaymentGatewayDriver> */
    private array $drivers = [];

    /**
     * @param iterable<PaymentGatewayDriver> $drivers
     */
    public function __construct(iterable $drivers)
    {
        foreach ($drivers as $driver) {
            $this->drivers[$driver->name()->value] = $driver;
        }
    }

    public function get(DriverName $name): PaymentGatewayDriver
    {
        return $this->drivers[$name->value] ?? throw UnknownDriver::named($name);
    }

    public function has(DriverName $name): bool
    {
        return isset($this->drivers[$name->value]);
    }

    public function all(): array
    {
        return array_values($this->drivers);
    }
}
