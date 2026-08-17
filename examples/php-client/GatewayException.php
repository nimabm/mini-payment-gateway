<?php

declare(strict_types=1);

namespace YourShop\Payments;

use RuntimeException;

/**
 * Carries the gateway's machine readable error code, so your shop can branch on
 * `duplicate_order_id` rather than matching on a message that may be
 * translated or reworded.
 */
final class GatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $statusCode,
    ) {
        parent::__construct($message);
    }

    /** True when retrying later might succeed. */
    public function isTransient(): bool
    {
        return in_array($this->errorCode, ['gateway_unreachable', 'rate_limited', 'internal_error'], true)
            || $this->statusCode >= 500;
    }
}
