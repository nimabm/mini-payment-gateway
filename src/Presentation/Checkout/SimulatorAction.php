<?php

declare(strict_types=1);

namespace App\Presentation\Checkout;

use App\Application\Shared\UrlBuilder;
use App\Domain\Gateway\GatewayRepository;
use App\Domain\Payment\PaymentId;
use App\Domain\Payment\PaymentRepository;
use App\Presentation\Support\TemplateRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * GET /simulator/{id}
 *
 * Stands in for a bank's payment page when the Simulator driver is used. Two
 * buttons: pay, or cancel. Both return to the normal callback endpoint, so the
 * code path being exercised is the real one.
 */
final readonly class SimulatorAction
{
    public function __construct(
        private PaymentRepository $payments,
        private GatewayRepository $gateways,
        private UrlBuilder $urls,
        private TemplateRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $arguments
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $slimResponse, array $arguments): ResponseInterface
    {
        $payment = $this->payments->find(PaymentId::fromString($arguments['id']));

        if ($payment === null) {
            return $this->renderer->render(
                new Response(404),
                'checkout/error.html.twig',
                ['titleKey' => 'checkout.failed_title', 'bodyKey' => 'checkout.failed_body'],
            );
        }

        $attempt = $payment->currentAttempt();
        $gateway = $attempt === null ? null : $this->gateways->find($attempt->gatewayId);

        if ($gateway === null) {
            return $this->renderer->render(
                new Response(409),
                'checkout/error.html.twig',
                ['titleKey' => 'checkout.failed_title', 'bodyKey' => 'checkout.failed_body'],
            );
        }

        $callback = $this->urls->gatewayCallback($gateway->id, $payment->id);

        return $this->renderer->render(new Response(), 'checkout/simulator.html.twig', [
            'payment' => $payment,
            'payUrl' => $callback . '?outcome=paid',
            'cancelUrl' => $callback . '?outcome=canceled',
        ]);
    }
}
