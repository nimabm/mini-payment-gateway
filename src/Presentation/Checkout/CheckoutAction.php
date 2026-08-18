<?php

declare(strict_types=1);

namespace App\Presentation\Checkout;

use App\Application\Payment\NoGatewayAvailable;
use App\Application\Payment\PaymentNotFound;
use App\Application\Payment\PaymentNotPayable;
use App\Application\Payment\StartCheckoutHandler;
use App\Domain\Payment\PaymentId;
use App\Presentation\Support\TemplateRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * GET /pay/{id}
 *
 * The page the payer lands on. It picks a gateway, opens the transaction and
 * forwards to the bank.
 *
 * The forward is a rendered page rather than a 302 — see the success path
 * below for why. The failure paths render real pages too, because a payer
 * whose payment could not be started needs to be told something.
 */
final readonly class CheckoutAction
{
    public function __construct(
        private StartCheckoutHandler $handler,
        private TemplateRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $arguments
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $slimResponse, array $arguments): ResponseInterface
    {
        try {
            $result = $this->handler->handle(PaymentId::fromString($arguments['id']));
        } catch (PaymentNotFound) {
            return $this->renderer->render(
                new Response(404),
                'checkout/error.html.twig',
                ['titleKey' => 'checkout.failed_title', 'bodyKey' => 'checkout.failed_body'],
            );
        } catch (PaymentNotPayable $e) {
            return $this->renderer->render(
                new Response(410),
                'checkout/error.html.twig',
                [
                    'titleKey' => 'checkout.expired_title',
                    'bodyKey' => 'checkout.failed_body',
                    'detail' => $e->getMessage(),
                ],
            );
        } catch (NoGatewayAvailable) {
            return $this->renderer->render(
                new Response(503),
                'checkout/error.html.twig',
                ['titleKey' => 'checkout.failed_title', 'bodyKey' => 'checkout.failed_body'],
            );
        }

        // A page that redirects itself, not a 302.
        //
        // ZarinPal matches the `Referer` of the request arriving at StartPay
        // against the domain registered for the gateway, and warns the payer
        // about an unknown origin when it does not match. A 302 carries no
        // `Referer` of its own, so the payer gets that warning between us and
        // the card form. Only a navigation started in the browser, from a
        // document on this domain, sends the header ZarinPal wants to see.
        //
        // The template starts that navigation from <head> with everything
        // inline, so the cost over the 302 is one small document and no extra
        // requests. See templates/checkout/redirect.html.twig.
        $response = $this->renderer->render(
            new Response(200),
            'checkout/redirect.html.twig',
            ['redirectUrl' => $result->redirectUrl],
        );

        // The bank's URL carries a single-use reference; caching this page
        // would send a later payer to a dead transaction.
        return $response
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->withHeader('Pragma', 'no-cache');
    }
}
