<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Reporting\TransactionFilter;
use App\Domain\Gateway\DriverName;
use App\Domain\Gateway\GatewayConfig;
use App\Domain\Merchant\Merchant;
use App\Domain\Payment\Payment;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\SqliteGatewayRepository;
use App\Infrastructure\Persistence\SqliteMerchantRepository;
use App\Infrastructure\Persistence\SqlitePaymentRepository;
use App\Infrastructure\Persistence\SqliteReportingRepository;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;

/**
 * The reporting read model. These are the numbers an operator makes decisions
 * with, so the aggregations get pinned down.
 */
final class ReportingTest extends DatabaseTestCase
{
    private SqliteReportingRepository $reporting;
    private SqlitePaymentRepository $payments;
    private Merchant $shopOne;
    private Merchant $shopTwo;
    private GatewayConfig $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $merchants = new SqliteMerchantRepository($this->pdo);
        $gateways = new SqliteGatewayRepository($this->pdo, $this->encryptor);
        $this->payments = new SqlitePaymentRepository($this->pdo);
        $this->reporting = new SqliteReportingRepository($this->pdo, 'Asia/Tehran');

        $this->shopOne = Merchant::register('Shop One', 'shop-one', Currency::IRT, $this->clock->now());
        $this->shopTwo = Merchant::register('Shop Two', 'shop-two', Currency::IRT, $this->clock->now());
        $merchants->save($this->shopOne);
        $merchants->save($this->shopTwo);

        $this->gateway = GatewayConfig::configure(
            DriverName::fromString('fake'),
            'Simulator',
            [],
            [Currency::IRT],
            true,
            $this->clock->now(),
        );
        $this->gateway->enable();
        $gateways->save($this->gateway);
    }

    /**
     * The state every installation is in the first time somebody signs in.
     *
     * `SUM(...)` over zero rows is NULL, not 0, and this query has no GROUP BY
     * — so without COALESCE the whole dashboard fails on a fresh install, which
     * is the worst possible moment for it to fail.
     */
    #[Test]
    public function it_reports_zeroes_on_an_empty_database(): void
    {
        $summary = $this->reporting->summarise(new TransactionFilter());

        self::assertSame(0, $summary->total);
        self::assertSame(0, $summary->paid);
        self::assertSame(0, $summary->failed);
        self::assertSame(0, $summary->open);
        self::assertSame(0.0, $summary->successRate());
        self::assertSame([], $summary->paidVolumeByCurrency);
    }

    /**
     * The other reports on an empty database, for the same reason.
     */
    #[Test]
    public function every_report_survives_an_empty_database(): void
    {
        $filter = new TransactionFilter();

        self::assertSame([], $this->reporting->dailyBreakdown($filter));
        self::assertSame([], $this->reporting->merchantBreakdown($filter));
        self::assertSame([], $this->reporting->gatewayBreakdown($filter));
        self::assertSame([], $this->reporting->topFailureReasons($filter));
        self::assertSame([], $this->reporting->export($filter));
        self::assertTrue($this->reporting->search($filter)->isEmpty());
        self::assertSame(array_fill(0, 24, 0), $this->reporting->hourlyDistribution($filter));
    }

    #[Test]
    public function it_summarises_a_period(): void
    {
        $this->paidPayment($this->shopOne, 100_000, '2024-08-16 08:00:00');
        $this->paidPayment($this->shopOne, 50_000, '2024-08-16 09:00:00');
        $this->failedPayment($this->shopOne, 70_000, '2024-08-16 09:30:00');

        $summary = $this->reporting->summarise(new TransactionFilter());

        self::assertSame(3, $summary->total);
        self::assertSame(2, $summary->paid);
        self::assertSame(1, $summary->failed);
        self::assertSame(150_000, $summary->paidVolumeByCurrency['IRT']);
        self::assertSame(66.67, $summary->successRate());
    }

    /**
     * Open payments are excluded from the success rate on purpose: counting a
     * payer who is still at the bank as a loss makes the number swing wildly
     * during busy periods.
     */
    #[Test]
    public function open_payments_do_not_drag_the_success_rate_down(): void
    {
        $this->paidPayment($this->shopOne, 100_000, '2024-08-16 08:00:00');
        $this->openPayment($this->shopOne, 100_000, '2024-08-16 08:30:00');

        $summary = $this->reporting->summarise(new TransactionFilter());

        self::assertSame(1, $summary->open);
        self::assertSame(100.0, $summary->successRate());
    }

    #[Test]
    public function it_breaks_totals_down_per_website(): void
    {
        $this->paidPayment($this->shopOne, 100_000, '2024-08-16 08:00:00');
        $this->paidPayment($this->shopTwo, 25_000, '2024-08-16 08:00:00');
        $this->paidPayment($this->shopTwo, 25_000, '2024-08-16 09:00:00');

        $rows = $this->reporting->merchantBreakdown(new TransactionFilter());

        $byName = [];

        foreach ($rows as $row) {
            $byName[$row->merchantName] = $row;
        }

        self::assertSame(100_000, $byName['Shop One']->paidVolume);
        self::assertSame(2, $byName['Shop Two']->paid);
        self::assertSame(25_000, $byName['Shop Two']->averageBasket());
    }

    #[Test]
    public function it_filters_by_website(): void
    {
        $this->paidPayment($this->shopOne, 100_000, '2024-08-16 08:00:00');
        $this->paidPayment($this->shopTwo, 100_000, '2024-08-16 08:00:00');

        $summary = $this->reporting->summarise(new TransactionFilter(merchantId: $this->shopOne->id));

        self::assertSame(1, $summary->total);
    }

    /**
     * The date range is the filter most likely to be silently wrong, so both
     * edges are checked.
     */
    #[Test]
    public function it_respects_the_date_range(): void
    {
        $this->paidPayment($this->shopOne, 10_000, '2024-08-14 12:00:00');
        $this->paidPayment($this->shopOne, 20_000, '2024-08-16 12:00:00');
        $this->paidPayment($this->shopOne, 30_000, '2024-08-18 12:00:00');

        $summary = $this->reporting->summarise(new TransactionFilter(
            from: $this->utc('2024-08-15 00:00:00'),
            to: $this->utc('2024-08-17 23:59:59'),
        ));

        self::assertSame(1, $summary->total);
        self::assertSame(20_000, $summary->paidVolumeByCurrency['IRT']);
    }

    #[Test]
    public function it_finds_a_payment_by_order_id_and_by_transaction_id(): void
    {
        $payment = $this->paidPayment($this->shopOne, 100_000, '2024-08-16 08:00:00');
        $transactionId = $payment->successfulAttempt()?->transactionId();

        self::assertNotNull($transactionId);

        self::assertSame(
            1,
            $this->reporting->search(new TransactionFilter(search: $payment->orderId))->total,
        );
        self::assertSame(
            1,
            $this->reporting->search(new TransactionFilter(search: $transactionId))->total,
        );
        self::assertSame(
            0,
            $this->reporting->search(new TransactionFilter(search: 'nothing-matches-this'))->total,
        );
    }

    #[Test]
    public function it_paginates(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->paidPayment($this->shopOne, 1_000, sprintf('2024-08-16 0%d:00:00', $i));
        }

        $page = $this->reporting->search(new TransactionFilter(perPage: 3, page: 2));

        self::assertCount(3, $page->items);
        self::assertSame(7, $page->total);
        self::assertSame(3, $page->pageCount());
        self::assertTrue($page->hasNext());
        self::assertTrue($page->hasPrevious());
    }

    #[Test]
    public function it_ranks_failure_reasons(): void
    {
        $this->failedPayment($this->shopOne, 1_000, '2024-08-16 08:00:00', 'insufficient_funds');
        $this->failedPayment($this->shopOne, 1_000, '2024-08-16 09:00:00', 'insufficient_funds');
        $this->failedPayment($this->shopOne, 1_000, '2024-08-16 10:00:00', 'canceled_by_payer');

        $reasons = $this->reporting->topFailureReasons(new TransactionFilter());

        self::assertSame('insufficient_funds', $reasons[0]->code);
        self::assertSame(2, $reasons[0]->count);
    }

    #[Test]
    public function the_export_ignores_paging(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->paidPayment($this->shopOne, 1_000, sprintf('2024-08-16 0%d:00:00', $i));
        }

        self::assertCount(5, $this->reporting->export(new TransactionFilter(perPage: 2)));
    }

    private function paidPayment(Merchant $merchant, int $amount, string $at): Payment
    {
        $when = $this->utc($at);
        $payment = $this->newPayment($merchant, $amount, $when);

        $payment->attachAttempt($this->gateway->id, 'AUTH-' . bin2hex(random_bytes(4)), $when);
        $payment->markPaid('TXN-' . bin2hex(random_bytes(4)), $when);

        $this->payments->save($payment);

        return $payment;
    }

    private function failedPayment(
        Merchant $merchant,
        int $amount,
        string $at,
        string $code = 'declined',
    ): Payment {
        $when = $this->utc($at);
        $payment = $this->newPayment($merchant, $amount, $when);

        $payment->attachAttempt($this->gateway->id, 'AUTH-' . bin2hex(random_bytes(4)), $when);
        $payment->fail($code, 'The bank declined the transaction.', $when);

        $this->payments->save($payment);

        return $payment;
    }

    private function openPayment(Merchant $merchant, int $amount, string $at): Payment
    {
        $when = $this->utc($at);
        $payment = $this->newPayment($merchant, $amount, $when);

        $payment->attachAttempt($this->gateway->id, 'AUTH-' . bin2hex(random_bytes(4)), $when);

        $this->payments->save($payment);

        return $payment;
    }

    private function newPayment(Merchant $merchant, int $amount, DateTimeImmutable $when): Payment
    {
        return Payment::create(
            merchantId: $merchant->id,
            orderId: 'ORDER-' . bin2hex(random_bytes(5)),
            amount: Money::of($amount, Currency::IRT),
            callbackUrl: 'https://shop.example.com/return',
            now: $when,
            expiresAt: $when->modify('+30 minutes'),
        );
    }

    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
