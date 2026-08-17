<?php

declare(strict_types=1);

namespace App\Application\Webhook;

final readonly class WebhookSendResult
{
    private function __construct(
        public bool $accepted,
        public ?int $statusCode,
        public ?string $error,
    ) {
    }

    public static function accepted(int $statusCode): self
    {
        return new self(true, $statusCode, null);
    }

    public static function rejected(?int $statusCode, string $error): self
    {
        return new self(false, $statusCode, $error);
    }
}
