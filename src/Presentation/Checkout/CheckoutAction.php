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
 * The success path is a bare 302 — see the redirect below for why. The failure
 * paths render real pages, because a payer whose payment could not be started
 * needs to be told something.
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

        // A plain 302, not an interstitial page.
        //
        // The payer is already waiting on the round trip to the bank, which is
        // where every measurable millisecond of this request goes. Rendering a
        // "taking you to your bank" page on top of that adds a document parse
        // and a render-blocking stylesheet request to the critical path, and
        // shows a flash of a page nobody wants to look at. The browser follows
        // this before it paints anything.
        //
        // The failure paths above still render real pages, because those are
        // the ones a payer actually needs to read.
        return (new Response(302))
            ->withHeader('Location', $result->redirectUrl)
            // The bank's URL carries a single-use reference; caching this
            // redirect would send a later payer to a dead transaction.
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->withHeader('Pragma', 'no-cache');
    }
}
