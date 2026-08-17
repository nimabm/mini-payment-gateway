<?php

declare(strict_types=1);

namespace App\Application\Reporting;

final readonly class FailureReason
{
    public function __construct(
        public string $code,
        public string $message,
        public int $count,
    ) {
    }
}
