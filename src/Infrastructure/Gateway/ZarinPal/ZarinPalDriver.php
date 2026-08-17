<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway\ZarinPal;

use App\Application\Gateway\CredentialField;
use App\Application\Gateway\PaymentGatewayDriver;
use App\Application\Gateway\PurchaseRequest;
use App\Application\Gateway\PurchaseResponse;
use App\Application\Gateway\RefundRequest;
use App\Application\Gateway\RefundResponse;
use App\Application\Gateway\VerificationRequest;
use App\Application\Gateway\VerificationResponse;
use App\Domain\Gateway\DriverName;
use App\Domain\Gateway\GatewayConfig;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Log\LoggerInterface;

/**
 * ZarinPal, using its Payment Gateway v4 JSON API.
 *
 * Everything ZarinPal-specific — its Authority/RefID vocabulary, its habit of
 * reporting "already verified" as error code 101, its Rial/Toman switch — is
 * contained in this file. Nothing outside it knows ZarinPal exists.
 *
 * @see https://docs.zarinpal.com/paymentGateway/
 */
final readonly class ZarinPalDriver implements PaymentGatewayDriver
{
    public const NAME = 'zarinpal';

    private const LIVE_API = 'https://payment.zarinpal.com/pg/v4/payment/';
    private const LIVE_STARTPAY = 'https://payment.zarinpal.com/pg/StartPay/';

    private const SANDBOX_API = 'https://sandbox.zarinpal.com/pg/v4/payment/';
    private const SANDBOX_STARTPAY = 'https://sandbox.zarinpal.com/pg/StartPay/';

    /** ZarinPal reports an already-verified transaction as a "failure". It is not one. */
    private const CODE_SUCCESS = 100;
    private const CODE_ALREADY_VERIFIED = 101;

    public function __construct(
        private ClientInterface $http,
        private LoggerInterface $logger,
    ) {
    }

    public function name(): DriverName
    {
        return DriverName::fromString(self::NAME);
    }

    public function displayName(): string
    {
        return 'ZarinPal';
    }

    public function supports(Currency $currency): bool
    {
        return $currency->isIranian();
    }

    public function credentialFields(): array
    {
        return [
            new CredentialField(
                key: 'merchant_id',
                label: 'Merchant ID',
                secret: true,
                required: true,
                hint: 'The 36 character UUID from your ZarinPal panel.',
            ),
        ];
    }

    public function purchase(PurchaseRequest $request): PurchaseResponse
    {
        $payload = [
            'merchant_id' => (string) $request->gateway->credential('merchant_id'),
            'amount' => $this->amountForApi($request->amount),
            'currency' => $request->amount->currency === Currency::IRT ? 'IRT' : 'IRR',
            'callback_url' => $request->callbackUrl,
            'description' => $request->description ?? sprintf('Order %s', $request->orderId),
        ];

        $metadata = array_filter([
            'email' => $request->payer->email,
            'mobile' => $request->payer->mobile,
        ]);

        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        $result = $this->call($request->gateway, 'request.json', $payload);

        if ($result === null) {
            return PurchaseResponse::failure(
                'gateway_unreachable',
                'ZarinPal could not be reached.',
                $this->redact($payload),
            );
        }

        $data = $this->sectionOf($result, 'data');
        $code = $this->intField($data, 'code');
        $authority = $this->stringField($data, 'authority') ?? '';

        if ($code !== self::CODE_SUCCESS || $authority === '') {
            [$errorCode, $errorMessage] = $this->extractError($result);

            return PurchaseResponse::failure(
                $errorCode,
                $errorMessage,
                $this->redact($payload),
                $result,
            );
        }

        $redirectUrl = $this->startPayUrl($request->gateway) . $authority;

        return PurchaseResponse::success(
            reference: $authority,
            redirectUrl: $redirectUrl,
            rawRequest: $this->redact($payload),
            // `redirect_url` is stored so a payer who reloads the checkout page
            // is sent back to the same ZarinPal session instead of a new one.
            rawResponse: $result + ['redirect_url' => $redirectUrl],
        );
    }

    public function verify(VerificationRequest $request): VerificationResponse
    {
        // ZarinPal sends `Status=NOK` when the payer cancels on its page. There
        // is nothing to verify in that case and asking would only log noise.
        if (strtoupper((string) $request->callbackParameter('Status')) === 'NOK') {
            return VerificationResponse::failed(
                'canceled_by_payer',
                'The payer canceled the payment on ZarinPal.',
            );
        }

        return $this->verifyWithApi($request);
    }

    public function inquire(VerificationRequest $request): VerificationResponse
    {
        // ZarinPal has no read-only status endpoint on this API version, so an
        // inquiry is a verification call. It is safe to repeat: a second call
        // returns code 101 rather than settling anything twice.
        return $this->verifyWithApi($request);
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        // ZarinPal's refund API requires a separate OAuth access token issued
        // per business, not the merchant id. Until that is configured, saying
        // so plainly beats pretending to support it.
        return RefundResponse::unsupported();
    }

    private function verifyWithApi(VerificationRequest $request): VerificationResponse
    {
        $payload = [
            'merchant_id' => (string) $request->gateway->credential('merchant_id'),
            'amount' => $this->amountForApi($request->amount),
            'authority' => $request->reference,
        ];

        $result = $this->call($request->gateway, 'verify.json', $payload);

        if ($result === null) {
            return VerificationResponse::failed(
                'gateway_unreachable',
                'ZarinPal could not be reached for verification.',
            );
        }

        $data = $this->sectionOf($result, 'data');
        $code = $this->intField($data, 'code');
        $refId = $this->stringField($data, 'ref_id') ?? '';
        $cardPan = $this->stringField($data, 'card_pan');
        $fee = isset($data['fee']) ? $this->intField($data, 'fee') : null;

        if ($code === self::CODE_SUCCESS && $refId !== '') {
            return VerificationResponse::paid($refId, $cardPan, $fee, $result);
        }

        if ($code === self::CODE_ALREADY_VERIFIED) {
            return VerificationResponse::alreadyVerified(
                $refId !== '' ? $refId : $request->reference,
                $cardPan,
                $fee,
                $result,
            );
        }

        [$errorCode, $errorMessage] = $this->extractError($result);

        return VerificationResponse::failed($errorCode, $errorMessage, $result);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null Null when the call could not be completed.
     */
    private function call(GatewayConfig $gateway, string $endpoint, array $payload): ?array
    {
        $url = $this->apiBaseUrl($gateway) . $endpoint;

        try {
            $response = $this->http->request('POST', $url, [
                'json' => $payload,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 20,
                'connect_timeout' => 5,
                // ZarinPal answers business rejections with a 4xx status, so
                // the body must be read rather than thrown away.
                'http_errors' => false,
            ]);

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode(
                (string) $response->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            return $decoded;
        } catch (GuzzleException | JsonException $e) {
            $this->logger->error('ZarinPal call failed.', [
                'endpoint' => $endpoint,
                'sandbox' => $gateway->isSandbox(),
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $result
     * @return array{string, string}
     */
    private function extractError(array $result): array
    {
        $errors = $this->sectionOf($result, 'errors');

        if ($errors !== []) {
            return [
                'zarinpal_' . ($this->stringField($errors, 'code') ?? 'unknown'),
                $this->stringField($errors, 'message') ?? 'ZarinPal rejected the request.',
            ];
        }

        $data = $this->sectionOf($result, 'data');

        return [
            'zarinpal_' . ($this->stringField($data, 'code') ?? 'unknown'),
            $this->stringField($data, 'message') ?? 'ZarinPal rejected the request.',
        ];
    }

    /**
     * Narrows one branch of a decoded JSON body to an array.
     *
     * `errors` is an object on failure and an empty array on success, which is
     * exactly the kind of shape that turns into a fatal if it is trusted.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function sectionOf(array $result, string $key): array
    {
        $section = $result[$key] ?? [];

        if (!is_array($section)) {
            return [];
        }

        /** @var array<string, mixed> $section */
        return $section;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stringField(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function intField(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * ZarinPal's v4 API takes the amount in the unit named by `currency`, so no
     * conversion is needed — only the guarantee that it is an integer.
     */
    private function amountForApi(Money $amount): int
    {
        return $amount->amount;
    }

    private function apiBaseUrl(GatewayConfig $gateway): string
    {
        return $gateway->isSandbox() ? self::SANDBOX_API : self::LIVE_API;
    }

    private function startPayUrl(GatewayConfig $gateway): string
    {
        return $gateway->isSandbox() ? self::SANDBOX_STARTPAY : self::LIVE_STARTPAY;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function redact(array $payload): array
    {
        if (isset($payload['merchant_id'])) {
            $payload['merchant_id'] = '***redacted***';
        }

        return $payload;
    }
}
