<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Gateway\DriverRegistry;
use App\Application\Gateway\GatewayRouter;
use App\Application\Gateway\PurchaseRequest;
use App\Application\Shared\UrlBuilder;
use App\Domain\Gateway\GatewayConfig;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentId;
use App\Domain\Payment\PaymentRepository;
use App\Domain\Shared\Clock;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Takes a created payment to a bank.
 *
 * Walks the routed gateways in order and stops at the first one that opens a
 * transaction. A PSP that rejects the request, times out or throws is recorded
 * as a failed attempt and the next one is tried, so a single dead bank does not
 * become a failed sale.
 */
final readonly class StartCheckoutHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private GatewayRouter $router,
        private DriverRegistry $drivers,
        private UrlBuilder $urls,
        private Clock $clock,
        private LoggerInterface $logger,
        private int $maxAttempts,
    ) {
    }

    public function handle(PaymentId $paymentId): CheckoutStarted
    {
        $payment = $this->payments->find($paymentId) ?? throw PaymentNotFound::withId($paymentId);

        $now = $this->clock->now();

        if ($payment->hasExpired($now) && $payment->status()->isOpen()) {
            $payment->expire($now);
            $this->payments->save($payment);

            throw PaymentNotPayable::forPayment($paymentId, $payment->status());
        }

        // A payer who reloads the checkout page mid-flight is sent back to the
        // bank they were already given, not charged on a second gateway.
        $current = $payment->currentAttempt();

        if ($payment->status()->isOpen() && $current?->status()->isFinal() === false) {
            $redirect = $this->redirectUrlFor($payment, $current->reference() ?? '');

            if ($redirect !== null) {
                return new CheckoutStarted($payment, $redirect, resumed: true);
            }
        }

        if (!$payment->status()->isOpen()) {
            throw PaymentNotPayable::forPayment($paymentId, $payment->status());
        }

        return $this->attemptGateways($payment);
    }

    private function attemptGateways(Payment $payment): CheckoutStarted
    {
        $candidates = $this->router->untriedCandidatesFor($payment);
        $tried = 0;

        foreach ($candidates as $gateway) {
            if ($tried >= $this->maxAttempts) {
                break;
            }

            $tried++;
            $result = $this->tryGateway($payment, $gateway);

            if ($result !== null) {
                $this->payments->save($payment);

                return $result;
            }
        }

        $this->payments->save($payment);

        throw NoGatewayAvailable::forPayment($payment->id);
    }

    private function tryGateway(Payment $payment, GatewayConfig $gateway): ?CheckoutStarted
    {
        $driver = $this->drivers->get($gateway->driver);
        $now = $this->clock->now();

        $request = new PurchaseRequest(
            gateway: $gateway,
            paymentId: $payment->id,
            amount: $payment->amount,
            callbackUrl: $this->urls->gatewayCallback($gateway->id, $payment->id),
            description: $payment->description,
            payer: $payment->payer,
            orderId: $payment->orderId,
        );

        try {
            $response = $driver->purchase($request);
        } catch (Throwable $e) {
            // A driver blowing up must not take the checkout down with it.
            $this->logger->error('Gateway driver threw during purchase.', [
                'payment_id' => $payment->id->value,
                'gateway_id' => $gateway->id->value,
                'driver' => $gateway->driver->value,
                'exception' => $e->getMessage(),
            ]);

            $payment->recordRejectedAttempt(
                $gateway->id,
                'driver_exception',
                $e->getMessage(),
                $now,
            );

            return null;
        }

        if (!$response->successful) {
            $this->logger->warning('Gateway refused to open a transaction.', [
                'payment_id' => $payment->id->value,
                'gateway_id' => $gateway->id->value,
                'error_code' => $response->errorCode,
            ]);

            $payment->recordRejectedAttempt(
                $gateway->id,
                $response->errorCode ?? 'purchase_failed',
                $response->errorMessage ?? 'The gateway refused the transaction.',
                $now,
                $response->rawRequest,
                $response->rawResponse,
            );

            return null;
        }

        $payment->attachAttempt(
            $gateway->id,
            (string) $response->reference,
            $now,
            $response->rawRequest,
            $response->rawResponse,
        );

        return new CheckoutStarted($payment, (string) $response->redirectUrl, resumed: false);
    }

    /**
     * Rebuilds the bank URL for an attempt that is already open, by asking the
     * driver again rather than storing a URL that may have expired.
     */
    private function redirectUrlFor(Payment $payment, string $reference): ?string
    {
        if ($reference === '') {
            return null;
        }

        $attempt = $payment->currentAttempt();

        if ($attempt === null) {
            return null;
        }

        $raw = $attempt->responsePayload();
        $url = $raw['redirect_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }
}
