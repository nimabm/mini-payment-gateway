<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Gateway;

use App\Application\Gateway\PurchaseRequest;
use App\Domain\Gateway\DriverName;
use App\Domain\Gateway\GatewayConfig;
use App\Domain\Payment\Payer;
use App\Domain\Payment\PaymentId;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use App\Infrastructure\Gateway\ZarinPal\ZarinPalDriver;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * The redirect URL is the one string in this driver that a payer's browser
 * follows without any further checks, so its shape is pinned here.
 */
final class ZarinPalDriverTest extends TestCase
{
    private const string AUTHORITY = 'A00000000000000000000000000000123456';

    public function test_it_sends_the_payer_to_zarinpal(): void
    {
        self::assertSame(
            'https://payment.zarinpal.com/pg/StartPay/' . self::AUTHORITY,
            $this->redirectUrlFor(sandbox: false),
        );
    }

    /**
     * The sandbox switch has to move the payer as well as the API call — a
     * gateway that talks to the sandbox but redirects to production hands the
     * payer a transaction that does not exist.
     */
    public function test_a_sandbox_gateway_redirects_to_the_sandbox(): void
    {
        self::assertSame(
            'https://sandbox.zarinpal.com/pg/StartPay/' . self::AUTHORITY,
            $this->redirectUrlFor(sandbox: true),
        );
    }

    public function test_the_merchant_id_is_the_only_credential_the_panel_asks_for(): void
    {
        $fields = $this->driver(new Response(200))->credentialFields();

        self::assertCount(1, $fields);
        self::assertSame('merchant_id', $fields[0]->key);
        self::assertTrue($fields[0]->secret);
        self::assertTrue($fields[0]->required);
    }

    private function redirectUrlFor(bool $sandbox): string
    {
        $gateway = GatewayConfig::configure(
            DriverName::fromString('zarinpal'),
            'ZarinPal',
            ['merchant_id' => str_repeat('a', 36)],
            [Currency::IRT],
            $sandbox,
            new DateTimeImmutable('2026-01-01 00:00:00'),
        );

        $response = new Response(200, [], (string) json_encode([
            'data' => ['code' => 100, 'authority' => self::AUTHORITY],
            'errors' => [],
        ]));

        $result = $this->driver($response)->purchase(new PurchaseRequest(
            gateway: $gateway,
            paymentId: PaymentId::generate(),
            amount: Money::of(50_000, Currency::IRT),
            callbackUrl: 'https://pay.example.com/callback/g/p',
            description: 'Test',
            payer: Payer::anonymous(),
            orderId: 'ORDER-1',
        ));

        self::assertTrue($result->successful, 'the purchase call should have succeeded');

        return (string) $result->redirectUrl;
    }

    private function driver(ResponseInterface $response): ZarinPalDriver
    {
        // Guzzle's own mock handler, because the driver depends on Guzzle's
        // client rather than PSR-18 — this exercises the real call path.
        $handler = HandlerStack::create(new MockHandler([$response]));

        return new ZarinPalDriver(new Client(['handler' => $handler]), new NullLogger());
    }
}
