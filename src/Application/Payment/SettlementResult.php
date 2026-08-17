<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Domain\Payment\Payment;

/**
 * The outcome of a settlement attempt.
 *
 * `Undetermined` is a first-class outcome, not an error: it means the PSP could
 * not be reached and the payer's money may or may not have moved. Collapsing it
 * into "failed" is how gateways lose people's money.
 */
final readonly class SettlementResult
{
    private function __construct(
        public Payment $payment,
        public SettlementOutcome $outcome,
        public ?string $reason = null,
    ) {
    }

    public static function settled(Payment $payment): self
    {
        return new self($payment, SettlementOutcome::Settled);
    }

    public static function alreadySettled(Payment $payment): self
    {
        return new self($payment, SettlementOutcome::AlreadySettled);
    }

    public static function failed(Payment $payment, string $reason): self
    {
        return new self($payment, SettlementOutcome::Failed, $reason);
    }

    public static function notSettled(Payment $payment, string $reason): self
    {
        return new self($payment, SettlementOutcome::Failed, $reason);
    }

    public static function undetermined(Payment $payment, string $reason): self
    {
        return new self($payment, SettlementOutcome::Undetermined, $reason);
    }

    public function isSuccessful(): bool
    {
        return $this->outcome === SettlementOutcome::Settled
            || $this->outcome === SettlementOutcome::AlreadySettled;
    }
}
