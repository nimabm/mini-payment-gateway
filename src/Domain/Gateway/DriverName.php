<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use InvalidArgumentException;

/**
 * The name of a driver implementation, e.g. "zarinpal".
 *
 * Deliberately a value object and not an enum: adding a PSP must never require
 * editing a type that the domain already depends on. A new driver registers
 * itself and the domain learns about it at runtime.
 */
final readonly class DriverName
{
    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));

        if (preg_match('/^[a-z][a-z0-9_]{1,31}$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid driver name.', $value));
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
