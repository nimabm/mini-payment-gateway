<?php

declare(strict_types=1);

namespace App\Application\Gateway;

/**
 * A driver's answer to "open a transaction".
 *
 * Drivers never throw for a business rejection — a declined amount or a wrong
 * merchant code is a normal outcome that the router must be able to inspect and
 * fail over from. Exceptions are reserved for genuine faults.
 */
final readonly class PurchaseResponse
{
    /**
     * @param array<string, mixed> $rawRequest
     * @param array<string, mixed> $rawResponse
     */
    private function __construct(
        public bool $successful,
        public ?string $reference,
        public ?string $redirectUrl,
        public ?string $errorCode,
        public ?string $errorMessage,
        public array $rawRequest,
        public array $rawResponse,
    ) {
    }

    /**
     * @param array<string, mixed> $rawRequest
     * @param array<string, mixed> $rawResponse
     */
    public static function success(
        string $reference,
        string $redirectUrl,
        array $rawRequest = [],
        array $rawResponse = [],
    ): self {
        return new self(true, $reference, $redirectUrl, null, null, $rawRequest, $rawResponse);
    }

    /**
     * @param array<string, mixed> $rawRequest
     * @param array<string, mixed> $rawResponse
     */
    public static function failure(
        string $errorCode,
        string $errorMessage,
        array $rawRequest = [],
        array $rawResponse = [],
    ): self {
        return new self(false, null, null, $errorCode, $errorMessage, $rawRequest, $rawResponse);
    }
}
