<?php

declare(strict_types=1);

namespace App\Application\Gateway;

final readonly class RefundResponse
{
    /**
     * @param array<string, mixed> $rawResponse
     */
    private function __construct(
        public bool $successful,
        public ?string $refundId,
        public ?string $errorCode,
        public ?string $errorMessage,
        public array $rawResponse,
    ) {
    }

    /**
     * @param array<string, mixed> $rawResponse
     */
    public static function success(string $refundId, array $rawResponse = []): self
    {
        return new self(true, $refundId, null, null, $rawResponse);
    }

    /**
     * @param array<string, mixed> $rawResponse
     */
    public static function failure(string $errorCode, string $errorMessage, array $rawResponse = []): self
    {
        return new self(false, null, $errorCode, $errorMessage, $rawResponse);
    }

    public static function unsupported(): self
    {
        return self::failure(
            'refund_unsupported',
            'This gateway does not support refunds through its API.',
        );
    }
}
