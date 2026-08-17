<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Gateway\DriverRegistry;
use App\Application\Gateway\VerificationRequest;
use App\Application\Webhook\WebhookPublisher;
use App\Domain\Gateway\GatewayRepository;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentId;
use App\Domain\Payment\PaymentRepository;
use App\Domain\Shared\Clock;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Verifies a payment against its PSP and settles it.
 *
 * This is the single place a payment becomes Paid. The payer's return, the
 * reconciliation worker and the manual button in the admin panel all funnel
 * through here, so the rules cannot drift between them.
 *
 * The operation is idempotent: calling it on an already settled payment
 * returns the existing outcome without touching the PSP.
 */
final readonly class SettlePaymentHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private GatewayRepository $gateways,
        private DriverRegistry $drivers,
        private WebhookPublisher $webhooks,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, string> $callbackParameters
     */
    public function handle(
        PaymentId $paymentId,
        array $callbackParameters = [],
        bool $viaInquiry = false,
    ): SettlementResult {
        $payment = $this->payments->find($paymentId) ?? throw PaymentNotFound::withId($paymentId);

        if ($payment->status()->isSuccessful()) {
            return SettlementResult::alreadySettled($payment);
        }

        if ($payment->status()->isFinal()) {
            return SettlementResult::notSettled($payment, 'payment_closed');
        }

        $attempt = $payment->currentAttempt();

        if ($attempt === null || $attempt->reference() === null) {
            return SettlementResult::notSettled($payment, 'no_open_attempt');
        }

        $gateway = $this->gateways->find($attempt->gatewayId);

        if ($gateway === null) {
            return SettlementResult::notSettled($payment, 'gateway_removed');
        }

        $now = $this->clock->now();

        // The payer is back; record that before talking to the PSP so a crash
        // mid-verification still leaves a payment reconciliation can find.
        if ($payment->status()->isOpen()) {
            $payment->markAwaitingVerification($now);
            $this->payments->save($payment);
        }

        $request = new VerificationRequest(
            gateway: $gateway,
            reference: $attempt->reference(),
            amount: $payment->amount,
            callbackParameters: $callbackParameters,
        );

        $driver = $this->drivers->get($gateway->driver);

        try {
            $response = $viaInquiry ? $driver->inquire($request) : $driver->verify($request);
        } catch (Throwable $e) {
            // Leaving the payment in AwaitingVerification is deliberate: an
            // unreachable PSP is exactly what reconciliation exists to retry.
            $this->logger->error('Verification call failed.', [
                'payment_id' => $paymentId->value,
                'gateway_id' => $gateway->id->value,
                'exception' => $e->getMessage(),
            ]);

            return SettlementResult::undetermined($payment, $e->getMessage());
        }

        if ($response->paid) {
            $payment->markPaid(
                (string) $response->transactionId,
                $this->clock->now(),
                $response->cardPan,
                $response->fee,
                $response->rawResponse,
            );

            $this->payments->save($payment);
            $this->webhooks->publishPaymentSucceeded($payment);

            $this->logger->info('Payment settled.', [
                'payment_id' => $paymentId->value,
                'transaction_id' => $response->transactionId,
                'already_verified' => $response->alreadyVerified,
            ]);

            return SettlementResult::settled($payment);
        }

        $payment->fail(
            $response->errorCode ?? 'verification_failed',
            $response->errorMessage ?? 'The gateway did not confirm this payment.',
            $this->clock->now(),
            $response->rawResponse,
        );

        $this->payments->save($payment);
        $this->webhooks->publishPaymentFailed($payment);

        return SettlementResult::failed($payment, $response->errorCode ?? 'verification_failed');
    }

    public function cancel(PaymentId $paymentId, string $reason): Payment
    {
        $payment = $this->payments->find($paymentId) ?? throw PaymentNotFound::withId($paymentId);

        if ($payment->status()->isOpen()) {
            $payment->cancel($this->clock->now(), $reason);
            $this->payments->save($payment);
            $this->webhooks->publishPaymentFailed($payment);
        }

        return $payment;
    }
}
