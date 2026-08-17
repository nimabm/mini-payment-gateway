<?php

declare(strict_types=1);

namespace App\Application\Gateway;

/**
 * A driver's answer to "did the money actually move?".
 */
final readonly class VerificationResponse
{
    /**
     * @param array<string, mixed> $rawResponse
     */
    private function __construct(
        public bool $paid,
        public ?string $transactionId,
        public ?string $cardPan,
        public ?int $fee,
        public ?string $errorCode,
        public ?string $errorMessage,
        public bool $alreadyVerified,
        public array $rawResponse,
    ) {
    }

    /**
     * @param array<string, mixed> $rawResponse
     */
    public static function paid(
        string $transactionId,
        ?string $cardPan = null,
        ?int $fee = null,
        array $rawResponse = [],
    ): self {
        return new self(true, $transactionId, $cardPan, $fee, null, null, false, $rawResponse);
    }

    /**
     * The PSP reports the transaction was verified by an earlier call. This is
     * a success, not a failure: it is what a retried callback looks like.
     *
     * @param array<string, mixed> $rawResponse
     */
    public static function alreadyVerified(
        string $transactionId,
        ?string $cardPan = null,
        ?int $fee = null,
        array $rawResponse = [],
    ): self {
        return new self(true, $transactionId, $cardPan, $fee, null, null, true, $rawResponse);
    }

    /**
     * @param array<string, mixed> $rawResponse
     */
    public static function failed(
        string $errorCode,
        string $errorMessage,
        array $rawResponse = [],
    ): self {
        return new self(false, null, null, null, $errorCode, $errorMessage, false, $rawResponse);
    }
}
