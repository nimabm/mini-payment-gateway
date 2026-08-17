<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Gateway\GatewayRouter;
use App\Application\Payment\CreatePaymentCommand;
use App\Application\Payment\CreatePaymentHandler;
use App\Application\Payment\NoGatewayAvailable;
use App\Application\Payment\SettlePaymentHandler;
use App\Application\Payment\StartCheckoutHandler;
use App\Application\Shared\UrlBuilder;
use App\Application\Webhook\WebhookPayloadFactory;
use App\Application\Webhook\WebhookPublisher;
use App\Domain\Gateway\DriverName;
use App\Domain\Gateway\GatewayConfig;
use App\Domain\Merchant\Merchant;
use App\Domain\Payment\PaymentStatus;
use App\Domain\Shared\Currency;
use App\Infrastructure\Gateway\ContainerDriverRegistry;
use App\Infrastructure\Gateway\Fake\FakeDriver;
use App\Infrastructure\Persistence\SqliteApiCredentialRepository;
use App\Infrastructure\Persistence\SqliteGatewayRepository;
use App\Infrastructure\Persistence\SqliteMerchantRepository;
use App\Infrastructure\Persistence\SqlitePaymentRepository;
use App\Infrastructure\Persistence\SqliteWebhookRepository;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

/**
 * The end-to-end test that matters: a merchant creates a payment, a payer goes
 * to a bank, comes back, and the money is confirmed — through the real
 * repositories, the real router and a real driver.
 */
final class PaymentFlowTest extends DatabaseTestCase
{
    private CreatePaymentHandler $create;
    private StartCheckoutHandler $checkout;
    private SettlePaymentHandler $settle;
    private SqlitePaymentRepository $payments;
    private SqliteGatewayRepository $gateways;
    private SqliteWebhookRepository $webhooks;
    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $urls = new UrlBuilder('https://gateway.test');

        $merchants = new SqliteMerchantRepository($this->pdo);
        $credentials = new SqliteApiCredentialRepository($this->pdo, $this->encryptor);
        $this->payments = new SqlitePaymentRepository($this->pdo);
        $this->gateways = new SqliteGatewayRepository($this->pdo, $this->encryptor);
        $this->webhooks = new SqliteWebhookRepository($this->pdo);

        $this->merchant = Merchant::register(
            'Demo Shop',
            'demo-shop',
            Currency::IRT,
            $this->clock->now(),
            'https://shop.example.com/webhook',
        );
        $merchants->save($this->merchant);

        $registry = new ContainerDriverRegistry([new FakeDriver($urls)]);

        $this->create = new CreatePaymentHandler(
            $this->payments,
            $merchants,
            $this->clock,
            $urls,
            30,
        );

        $this->checkout = new StartCheckoutHandler(
            $this->payments,
            new GatewayRouter($this->gateways),
            $registry,
            $urls,
            $this->clock,
            new NullLogger(),
            3,
        );

        $this->settle = new SettlePaymentHandler(
            $this->payments,
            $this->gateways,
            $registry,
            new WebhookPublisher(
                $this->webhooks,
                $merchants,
                new WebhookPayloadFactory($this->clock),
                $this->clock,
            ),
            $this->clock,
            new NullLogger(),
        );
    }

    #[Test]
    public function a_payment_goes_from_creation_to_paid(): void
    {
        $this->enableGateway('Simulator', []);

        $created = $this->create->handle($this->command());

        self::assertSame(PaymentStatus::Created, $created->payment->status());
        self::assertStringContainsString('/pay/', $created->checkoutUrl);

        $checkout = $this->checkout->handle($created->payment->id);

        self::assertStringContainsString('/simulator/', $checkout->redirectUrl);
        self::assertSame(PaymentStatus::Pending, $checkout->payment->status());

        $result = $this->settle->handle($created->payment->id, ['outcome' => 'paid']);

        self::assertTrue($result->isSuccessful());
        self::assertSame(PaymentStatus::Paid, $result->payment->status());
        self::assertNotNull($result->payment->successfulAttempt()?->transactionId());
    }

    #[Test]
    public function a_successful_payment_queues_a_webhook(): void
    {
        $this->enableGateway('Simulator', []);

        $created = $this->create->handle($this->command());
        $this->checkout->handle($created->payment->id);
        $this->settle->handle($created->payment->id, ['outcome' => 'paid']);

        $deliveries = $this->webhooks->findForPayment($created->payment->id);

        self::assertCount(1, $deliveries);
        self::assertSame('payment.succeeded', $deliveries[0]->event);

        $data = $deliveries[0]->payload['data'];

        self::assertIsArray($data);
        self::assertSame('paid', $data['status']);
    }

    #[Test]
    public function a_canceled_payment_ends_as_failed(): void
    {
        $this->enableGateway('Simulator', []);

        $created = $this->create->handle($this->command());
        $this->checkout->handle($created->payment->id);

        $result = $this->settle->handle($created->payment->id, ['outcome' => 'canceled']);

        self::assertFalse($result->isSuccessful());
        self::assertSame(PaymentStatus::Failed, $result->payment->status());
    }

    /**
     * The behaviour the whole aggregator exists for: one PSP refuses, the payer
     * never finds out, the next one takes the payment.
     */
    #[Test]
    public function it_fails_over_to_the_next_gateway(): void
    {
        $broken = $this->enableGateway('Broken', ['behaviour' => 'reject_purchase'], priority: 10);
        $working = $this->enableGateway('Simulator', [], priority: 20);

        $created = $this->create->handle($this->command());
        $checkout = $this->checkout->handle($created->payment->id);

        $payment = $this->payments->find($created->payment->id);

        self::assertNotNull($payment);
        self::assertSame(2, $payment->attemptCount());
        self::assertSame($broken->id->value, $payment->attempts()[0]->gatewayId->value);
        self::assertSame($working->id->value, $payment->attempts()[1]->gatewayId->value);
        self::assertStringContainsString('/simulator/', $checkout->redirectUrl);
    }

    #[Test]
    public function it_reports_when_no_gateway_can_take_the_payment(): void
    {
        $this->enableGateway('Broken', ['behaviour' => 'reject_purchase']);

        $created = $this->create->handle($this->command());

        $this->expectException(NoGatewayAvailable::class);

        $this->checkout->handle($created->payment->id);
    }

    /**
     * A replayed create must never produce a second payment — this is the
     * guarantee that stops a retried HTTP request charging a customer twice.
     */
    #[Test]
    public function an_idempotency_key_returns_the_same_payment(): void
    {
        $this->enableGateway('Simulator', []);

        $first = $this->create->handle($this->command(idempotencyKey: 'key-1'));
        $second = $this->create->handle($this->command(idempotencyKey: 'key-1'));

        self::assertSame($first->payment->id->value, $second->payment->id->value);
        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed);
    }

    /**
     * Settling twice is what a redelivered callback looks like. It must not
     * throw, and it must not change the outcome.
     */
    #[Test]
    public function settling_twice_is_idempotent(): void
    {
        $this->enableGateway('Simulator', []);

        $created = $this->create->handle($this->command());
        $this->checkout->handle($created->payment->id);

        $first = $this->settle->handle($created->payment->id, ['outcome' => 'paid']);
        $second = $this->settle->handle($created->payment->id, ['outcome' => 'paid']);

        self::assertTrue($first->isSuccessful());
        self::assertTrue($second->isSuccessful());
        self::assertSame(
            $first->payment->successfulAttempt()?->transactionId(),
            $second->payment->successfulAttempt()?->transactionId(),
        );
        self::assertCount(1, $this->webhooks->findForPayment($created->payment->id));
    }

    #[Test]
    public function a_returning_payer_is_sent_back_to_the_same_bank_session(): void
    {
        $this->enableGateway('Simulator', []);

        $created = $this->create->handle($this->command());

        $first = $this->checkout->handle($created->payment->id);
        $second = $this->checkout->handle($created->payment->id);

        self::assertFalse($first->resumed);
        self::assertTrue($second->resumed);
        self::assertSame($first->redirectUrl, $second->redirectUrl);
        self::assertSame(1, $this->payments->find($created->payment->id)?->attemptCount());
    }

    #[Test]
    public function an_expired_payment_cannot_be_paid(): void
    {
        $this->enableGateway('Simulator', []);

        $created = $this->create->handle($this->command());

        $this->clock->advance('+31 minutes');

        $this->expectException(\App\Application\Payment\PaymentNotPayable::class);

        $this->checkout->handle($created->payment->id);
    }

    /**
     * @param array<string, string> $credentials
     */
    private function enableGateway(string $label, array $credentials, int $priority = 100): GatewayConfig
    {
        $gateway = GatewayConfig::configure(
            DriverName::fromString(FakeDriver::NAME),
            $label,
            $credentials,
            [Currency::IRT],
            true,
            $this->clock->now(),
            $priority,
        );
        $gateway->enable();

        $this->gateways->save($gateway);

        $assigned = $this->gateways->assignedIds($this->merchant->id);
        $assigned[] = $gateway->id;

        $this->gateways->assignToMerchant($this->merchant->id, $assigned);

        return $gateway;
    }

    private function command(?string $idempotencyKey = null): CreatePaymentCommand
    {
        return new CreatePaymentCommand(
            merchantId: $this->merchant->id,
            amount: 150_000,
            currency: Currency::IRT,
            orderId: 'ORDER-' . ($idempotencyKey ?? bin2hex(random_bytes(4))),
            callbackUrl: 'https://shop.example.com/return',
            description: 'Test order',
            idempotencyKey: $idempotencyKey,
        );
    }
}
