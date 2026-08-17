<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Base class for the opaque, publicly exposed identifiers of aggregates.
 *
 * UUIDv7 is used so identifiers sort by creation time, which keeps SQLite's
 * B-tree indexes compact without leaking a guessable sequence.
 */
abstract readonly class Identifier
{
    final private function __construct(public string $value)
    {
        if (!Uuid::isValid($value)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a valid %s.',
                $value,
                static::class,
            ));
        }
    }

    public static function generate(): static
    {
        return new static(Uuid::uuid7()->toString());
    }

    public static function fromString(string $value): static
    {
        return new static($value);
    }

    public function equals(self $other): bool
    {
        return $other::class === static::class && $other->value === $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
