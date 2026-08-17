<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway\Fake;

use App\Application\Gateway\CredentialField;
use App\Application\Gateway\PaymentGatewayDriver;
use App\Application\Gateway\PurchaseRequest;
use App\Application\Gateway\PurchaseResponse;
use App\Application\Gateway\RefundRequest;
use App\Application\Gateway\RefundResponse;
use App\Application\Gateway\VerificationRequest;
use App\Application\Gateway\VerificationResponse;
use App\Application\Shared\UrlBuilder;
use App\Domain\Gateway\DriverName;
use App\Domain\Shared\Currency;

/**
 * A simulated bank.
 *
 * Instead of redirecting to a PSP it redirects to a local page where you press
 * "pay" or "cancel". That makes the entire flow — routing, failover, callback,
 * verification, webhooks, reports — exercisable end to end with no bank
 * account, no internet and no shared sandbox that someone else's test data
 * keeps polluting.
 *
 * It is also the fixture the integration tests run against.
 */
final readonly class FakeDriver implements PaymentGatewayDriver
{
    public const NAME = 'fake';

    public function __construct(private UrlBuilder $urls)
    {
    }

    public function name(): DriverName
    {
        return DriverName::fromString(self::NAME);
    }

    public function displayName(): string
    {
        return 'Simulator (test only)';
    }

    public function supports(Currency $currency): bool
    {
        return true;
    }

    public function credentialFields(): array
    {
        return [
            new CredentialField(
                key: 'behaviour',
                label: 'Behaviour',
                secret: false,
                required: false,
                hint: 'Leave empty for the interactive simulator, or set to '
                    . '"always_paid", "always_failed" or "reject_purchase".',
            ),
        ];
    }

    public function purchase(PurchaseRequest $request): PurchaseResponse
    {
        $behaviour = $request->gateway->credential('behaviour') ?? '';

        if ($behaviour === 'reject_purchase') {
            return PurchaseResponse::failure(
                'simulated_rejection',
                'The simulator was configured to reject this purchase.',
            );
        }

        $reference = 'FAKE-' . strtoupper(bin2hex(random_bytes(8)));

        $redirectUrl = sprintf(
            '%s/simulator/%s?reference=%s',
            $this->urls->baseUrl(),
            $request->paymentId->value,
            urlencode($reference),
        );

        return PurchaseResponse::success(
            reference: $reference,
            redirectUrl: $redirectUrl,
            rawRequest: [
                'amount' => $request->amount->amount,
                'currency' => $request->amount->currency->value,
                'callback_url' => $request->callbackUrl,
            ],
            rawResponse: [
                'reference' => $reference,
                'redirect_url' => $redirectUrl,
                'simulated' => true,
            ],
        );
    }

    public function verify(VerificationRequest $request): VerificationResponse
    {
        $behaviour = $request->gateway->credential('behaviour') ?? '';

        if ($behaviour === 'always_failed') {
            return VerificationResponse::failed(
                'simulated_failure',
                'The simulator was configured to fail every verification.',
            );
        }

        $outcome = $request->callbackParameter('outcome') ?? 'paid';

        if ($behaviour !== 'always_paid' && $outcome !== 'paid') {
            return VerificationResponse::failed(
                'canceled_by_payer',
                'The payer canceled on the simulator page.',
            );
        }

        return VerificationResponse::paid(
            transactionId: 'FAKETXN-' . strtoupper(bin2hex(random_bytes(6))),
            cardPan: '621986******1234',
            fee: 0,
            rawResponse: ['simulated' => true, 'reference' => $request->reference],
        );
    }

    public function inquire(VerificationRequest $request): VerificationResponse
    {
        return $this->verify($request);
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        return RefundResponse::success(
            'FAKEREFUND-' . strtoupper(bin2hex(random_bytes(6))),
            ['simulated' => true, 'amount' => $request->amount->amount],
        );
    }
}
