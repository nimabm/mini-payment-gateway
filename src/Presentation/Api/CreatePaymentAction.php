<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Application\Payment\CreatePaymentCommand;
use App\Application\Payment\CreatePaymentHandler;
use App\Domain\Merchant\Merchant;
use App\Domain\Shared\Currency;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/payments
 */
final readonly class CreatePaymentAction
{
    public function __construct(
        private CreatePaymentHandler $handler,
        private PaymentPresenter $presenter,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Merchant $merchant */
        $merchant = $request->getAttribute(ApiAuthenticationMiddleware::ATTRIBUTE_MERCHANT);

        $input = RequestPayload::fromRequest($request);

        $errors = $this->validate($input);

        if ($errors !== []) {
            return ApiResponse::error(
                'validation_failed',
                'The request payload is invalid.',
                422,
                $errors,
            );
        }

        $result = $this->handler->handle(new CreatePaymentCommand(
            merchantId: $merchant->id,
            amount: $input->int('amount'),
            currency: Currency::from($input->string('currency', $merchant->defaultCurrency()->value)),
            orderId: $input->string('order_id'),
            callbackUrl: $input->string('callback_url'),
            description: $input->nullableString('description'),
            payerName: $input->nullableString('payer_name'),
            payerEmail: $input->nullableString('payer_email'),
            payerMobile: $input->nullableString('payer_mobile'),
            idempotencyKey: $input->nullableString('idempotency_key')
                ?? ($request->getHeaderLine('Idempotency-Key') ?: null),
            preferredGateway: $input->nullableString('gateway_id'),
        ));

        return ApiResponse::success(
            $this->presenter->present($result->payment, $result->checkoutUrl),
            // A replayed idempotent request is not a creation.
            $result->replayed ? 200 : 201,
        );
    }

    /**
     * @return array<string, string>
     */
    private function validate(RequestPayload $input): array
    {
        $errors = [];

        if ($input->int('amount') <= 0) {
            $errors['amount'] = 'Amount must be a positive integer in the currency\'s minor unit.';
        }

        if ($input->string('order_id') === '') {
            $errors['order_id'] = 'An order id is required.';
        }

        $callback = $input->string('callback_url');

        if (filter_var($callback, FILTER_VALIDATE_URL) === false) {
            $errors['callback_url'] = 'A valid absolute callback URL is required.';
        }

        $currency = $input->nullableString('currency');

        if ($currency !== null && Currency::tryFrom($currency) === null) {
            $errors['currency'] = sprintf('"%s" is not a supported currency.', $currency);
        }

        $email = $input->nullableString('payer_email');

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['payer_email'] = 'The payer email is not a valid address.';
        }

        return $errors;
    }
}
