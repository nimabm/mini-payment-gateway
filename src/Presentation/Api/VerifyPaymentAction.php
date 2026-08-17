<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Application\Payment\SettlePaymentHandler;
use App\Domain\Merchant\Merchant;
use App\Domain\Payment\PaymentId;
use App\Domain\Payment\PaymentRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/payments/{id}/verify
 *
 * Normally unnecessary — the gateway verifies as soon as the payer returns —
 * but exposed so a merchant can force the question after a lost callback
 * instead of waiting for reconciliation. Safe to call repeatedly.
 */
final readonly class VerifyPaymentAction
{
    public function __construct(
        private PaymentRepository $payments,
        private SettlePaymentHandler $settle,
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

        $paymentId = PaymentId::fromString($arguments['id']);
        $payment = $this->payments->find($paymentId);

        if ($payment === null || !$payment->merchantId->equals($merchant->id)) {
            return ApiResponse::error('payment_not_found', 'No such payment.', 404);
        }

        $result = $this->settle->handle($paymentId, viaInquiry: true);

        return ApiResponse::success(
            $this->presenter->present($result->payment) + ['outcome' => $result->outcome->value],
        );
    }
}
