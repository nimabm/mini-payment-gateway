<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Domain\Merchant\Merchant;
use App\Domain\Payment\PaymentId;
use App\Domain\Payment\PaymentRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/payments/{id}
 *
 * The authoritative answer to "was this order paid?". Merchant modules should
 * treat this — not the browser redirect — as the source of truth.
 */
final readonly class GetPaymentAction
{
    public function __construct(
        private PaymentRepository $payments,
        private PaymentPresenter $presenter,
    ) {
    }

    /**
     * @param array<string, string> $arguments
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $slimResponse, array $arguments): ResponseInterface
    {
        /** @var Merchant $merchant */
        $merchant = $request->getAttribute(ApiAuthenticationMiddleware::ATTRIBUTE_MERCHANT);

        $payment = $this->payments->find(PaymentId::fromString($arguments['id']));

        // Answering "not found" rather than "forbidden" for another merchant's
        // payment avoids confirming that the id exists at all.
        if ($payment === null || !$payment->merchantId->equals($merchant->id)) {
            return ApiResponse::error('payment_not_found', 'No such payment.', 404);
        }

        return ApiResponse::success($this->presenter->present($payment));
    }
}
