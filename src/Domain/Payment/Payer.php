<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * Optional contact details for the person paying. Everything is nullable: the
 * gateway must work for merchants that collect nothing but an amount.
 */
final readonly class Payer
{
    public function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public ?string $mobile = null,
    ) {
    }

    public static function anonymous(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->name === null && $this->email === null && $this->mobile === null;
    }
}
