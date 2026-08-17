<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Domain\Payment\PaymentRepository;
use App\Domain\Shared\Clock;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Chases payments stuck in AwaitingVerification.
 *
 * A payer whose browser died on the bank's page, a callback lost to a network
 * blip, a PSP that was down when we tried to verify — all of them leave a payer
 * charged and a merchant unaware. This job asks the PSP what really happened and
 * settles the payment either way.
 */
final readonly class ReconcilePaymentsHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private SettlePaymentHandler $settle,
        private Clock $clock,
        private LoggerInterface $logger,
        /** Grace period before a returned payer is considered stuck. */
        private int $graceMinutes = 5,
    ) {
    }

    /**
     * @return int Number of payments examined.
     */
    public function handle(int $batchSize = 100): int
    {
        $cutoff = $this->clock->now()->modify(sprintf('-%d minutes', $this->graceMinutes));
        $stuck = $this->payments->findAwaitingVerification($cutoff, $batchSize);

        foreach ($stuck as $payment) {
            try {
                // Inquiry rather than verification: we are asking what the PSP
                // believes, and drivers that separate the two must not settle
                // a transaction as a side effect of being asked.
                $result = $this->settle->handle($payment->id, viaInquiry: true);

                $this->logger->info('Reconciled a stuck payment.', [
                    'payment_id' => $payment->id->value,
                    'outcome' => $result->outcome->value,
                ]);
            } catch (Throwable $e) {
                $this->logger->error('Reconciliation failed for a payment.', [
                    'payment_id' => $payment->id->value,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return count($stuck);
    }
}
