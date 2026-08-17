<?php

declare(strict_types=1);

namespace App\Presentation\Checkout;

use App\Application\Payment\PaymentNotFound;
use App\Application\Payment\SettlePaymentHandler;
use App\Domain\Merchant\ApiCredentialRepository;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentId;
use App\Infrastructure\Security\CallbackSigner;
use App\Presentation\Support\TemplateRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * GET|POST /callback/{gatewayId}/{paymentId}
 *
 * Where the PSP returns the payer. Verifies the payment and then bounces the
 * payer back to the merchant with a signed result.
 *
 * This endpoint is unauthenticated by necessity — the bank sends the payer's
 * browser here — so it must be safe to call repeatedly, by anyone, in any
 * order. All of that safety lives in the settle handler, which is idempotent.
 */
final readonly class GatewayCallbackAction
{
    public function __construct(
        private SettlePaymentHandler $settle,
        private ApiCredentialRepository $credentials,
        private CallbackSigner $signer,
        private TemplateRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $arguments
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $slimResponse, array $arguments): ResponseInterface
    {
        $paymentId = PaymentId::fromString($arguments['paymentId']);

        try {
            $result = $this->settle->handle($paymentId, $this->callbackParameters($request));
        } catch (PaymentNotFound) {
            return $this->renderer->render(
                new Response(404),
                'checkout/error.html.twig',
                ['titleKey' => 'checkout.failed_title', 'bodyKey' => 'checkout.failed_body'],
            );
        }

        return $this->redirectToMerchant($result->payment);
    }

    private function redirectToMerchant(Payment $payment): ResponseInterface
    {
        $secret = $this->signingSecretFor($payment);

        $parameters = $secret === null
            ? ['payment_id' => $payment->id->value, 'status' => $payment->status()->value]
            : $this->signer->signRedirect($payment, $secret);

        $separator = str_contains($payment->callbackUrl, '?') ? '&' : '?';
        $url = $payment->callbackUrl . $separator . http_build_query($parameters);

        return (new Response(302))->withHeader('Location', $url);
    }

    private function signingSecretFor(Payment $payment): ?string
    {
        foreach ($this->credentials->findForMerchant($payment->merchantId) as $credential) {
            if ($credential->isActive()) {
                return $credential->secret;
            }
        }

        return null;
    }

    /**
     * PSPs are split between GET and POST callbacks, so both are merged.
     *
     * @return array<string, string>
     */
    private function callbackParameters(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        $body = is_array($parsed) ? $parsed : [];

        $merged = array_merge($request->getQueryParams(), $body);

        $parameters = [];

        foreach ($merged as $key => $value) {
            if (is_scalar($value)) {
                $parameters[(string) $key] = (string) $value;
            }
        }

        return $parameters;
    }
}
