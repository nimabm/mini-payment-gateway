<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Reporting\DailyBucket;
use App\Application\Reporting\FailureReason;
use App\Application\Reporting\GatewayBreakdown;
use App\Application\Reporting\MerchantBreakdown;
use App\Application\Reporting\Paginated;
use App\Application\Reporting\ReportingRepository;
use App\Application\Reporting\Summary;
use App\Application\Reporting\TransactionFilter;
use App\Application\Reporting\TransactionRow;
use App\Domain\Payment\AttemptStatus;
use App\Domain\Payment\PaymentStatus;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * The read side, written as explicit SQL.
 *
 * Every method builds on the same WHERE clause so the transaction list, the
 * charts and the CSV export can never disagree about what "this period" means.
 *
 * The join to `payment_attempts` picks the successful attempt when there is
 * one and otherwise the last one tried, which is what an operator means by
 * "the gateway this payment went through".
 */
final readonly class SqliteReportingRepository implements ReportingRepository
{
    public function __construct(
        private PDO $pdo,
        private string $displayTimezone = 'UTC',
    ) {
    }

    public function search(TransactionFilter $filter): Paginated
    {
        [$where, $parameters] = $this->buildWhere($filter);

        $total = Rows::int(
            $this->pdo,
            sprintf('SELECT COUNT(*) FROM payments p WHERE %s', $where),
            $parameters,
        );

        $rows = Rows::all(
            $this->pdo,
            sprintf(
                '%s WHERE %s ORDER BY p.created_at DESC LIMIT :limit OFFSET :offset',
                $this->rowSelect(),
                $where,
            ),
            $parameters + ['limit' => $filter->perPage, 'offset' => $filter->offset()],
        );

        return new Paginated(
            array_map($this->toTransactionRow(...), $rows),
            $total,
            $filter->page,
            $filter->perPage,
        );
    }

    public function export(TransactionFilter $filter): array
    {
        return $this->search($filter->unpaginated())->items;
    }

    public function summarise(TransactionFilter $filter): Summary
    {
        [$where, $parameters] = $this->buildWhere($filter);

        // Every SUM below is wrapped in COALESCE. This query has no GROUP BY,
        // so against an empty database it returns one row of NULLs rather than
        // no rows at all — which is the state a freshly installed gateway is
        // in the first time an operator opens the dashboard.

        $row = Rows::one(
            $this->pdo,
            sprintf(
                'SELECT
                    COUNT(*)                                              AS total,
                    COALESCE(SUM(CASE WHEN p.status IN (%s) THEN 1 ELSE 0 END), 0)     AS paid,
                    COALESCE(SUM(CASE WHEN p.status IN (%s) THEN 1 ELSE 0 END), 0)     AS failed,
                    COALESCE(SUM(CASE WHEN p.status IN (%s) THEN 1 ELSE 0 END), 0)     AS open
                 FROM payments p
                 WHERE %s',
                $this->quotedStatuses($this->successfulStatuses()),
                $this->quotedStatuses($this->failedStatuses()),
                $this->quotedStatuses($this->openStatuses()),
                $where,
            ),
            $parameters,
        );

        if ($row === null) {
            return Summary::empty();
        }

        $volumeRows = Rows::all(
            $this->pdo,
            sprintf(
                'SELECT p.currency, COALESCE(SUM(p.amount), 0) AS volume, COALESCE(SUM(p.refunded_amount), 0) AS refunded
                 FROM payments p
                 WHERE %s AND p.status IN (%s)
                 GROUP BY p.currency',
                $where,
                $this->quotedStatuses($this->successfulStatuses()),
            ),
            $parameters,
        );

        $paidVolume = [];
        $refundedVolume = [];

        foreach ($volumeRows as $volumeRow) {
            $currency = $volumeRow->string('currency');
            $paidVolume[$currency] = $volumeRow->int('volume');
            $refundedVolume[$currency] = $volumeRow->int('refunded');
        }

        return new Summary(
            total: $row->int('total'),
            paid: $row->int('paid'),
            failed: $row->int('failed'),
            open: $row->int('open'),
            paidVolumeByCurrency: $paidVolume,
            refundedVolumeByCurrency: $refundedVolume,
        );
    }

    public function dailyBreakdown(TransactionFilter $filter): array
    {
        [$where, $parameters] = $this->buildWhere($filter);

        // Grouping happens in the display timezone, otherwise "yesterday" in
        // Tehran would be split across two UTC days in the report.
        $day = $this->localDayExpression('p.created_at');

        $rows = Rows::all(
            $this->pdo,
            sprintf(
                'SELECT
                %s                                                 AS day,
                p.currency                                         AS currency,
                COUNT(*)                                           AS total,
                COALESCE(SUM(CASE WHEN p.status IN (%s) THEN 1 ELSE 0 END), 0)  AS paid,
                COALESCE(SUM(CASE WHEN p.status IN (%s) THEN 1 ELSE 0 END), 0)  AS failed,
                COALESCE(SUM(CASE WHEN p.status IN (%s) THEN p.amount ELSE 0 END), 0) AS paid_volume
             FROM payments p
             WHERE %s
             GROUP BY day, p.currency
             ORDER BY day',
                $day,
                $this->quotedStatuses($this->successfulStatuses()),
                $this->quotedStatuses($this->failedStatuses()),
                $this->quotedStatuses($this->successfulStatuses()),
                $where,
            ),
            $parameters,
        );

        return array_map(
            static fn (Row $row): DailyBucket => new DailyBucket(
                new DateTimeImmutable($row->string('day'), new DateTimeZone('UTC')),
                $row->int('total'),
                $row->int('paid'),
                $row->int('failed'),
                $row->int('paid_volume'),
                $row->string('currency'),
            ),
            $rows,
        );
    }

    public function merchantBreakdown(TransactionFilter $filter): array
    {
        [$where, $parameters] = $this->buildWhere($filter);

        $rows = Rows::all(
            $this->pdo,
            sprintf(
                'SELECT
                m.id                                               AS merchant_id,
                m.name                                             AS merchant_name,
                p.currency                                         AS currency,
                COUNT(*)                                           AS total,
                COALESCE(SUM(CASE WHEN p.status IN (%s) THEN 1 ELSE 0 END), 0)  AS paid,
                COALESCE(SUM(CASE WHEN p.status IN (%s) THEN 1 ELSE 0 END), 0)  AS failed,
                COALESCE(SUM(CASE WHEN p.status IN (%s) THEN p.amount ELSE 0 END), 0) AS paid_volume
             FROM payments p
             INNER JOIN merchants m ON m.id = p.merchant_id
             WHERE %s
             GROUP BY m.id, p.currency
             ORDER BY paid_volume DESC',
                $this->quotedStatuses($this->successfulStatuses()),
                $this->quotedStatuses($this->failedStatuses()),
                $this->quotedStatuses($this->successfulStatuses()),
                $where,
            ),
            $parameters,
        );

        return array_map(
            static fn (Row $row): MerchantBreakdown => new MerchantBreakdown(
                $row->string('merchant_id'),
                $row->string('merchant_name'),
                $row->int('total'),
                $row->int('paid'),
                $row->int('failed'),
                $row->int('paid_volume'),
                $row->string('currency'),
            ),
            $rows,
        );
    }

    public function gatewayBreakdown(TransactionFilter $filter): array
    {
        [$where, $parameters] = $this->buildWhere($filter);

        // Counted per attempt rather than per payment: a payment that failed
        // over from one gateway to another must show up as a loss for the first
        // and a win for the second, which is the whole point of the comparison.
        $rows = Rows::all(
            $this->pdo,
            sprintf(
                'SELECT
                g.id       AS gateway_id,
                g.label    AS gateway_label,
                g.driver   AS driver,
                g.sandbox  AS sandbox,
                p.currency AS currency,
                COUNT(*)   AS attempts,
                COALESCE(SUM(CASE WHEN a.status = :succeeded THEN 1 ELSE 0 END), 0) AS succeeded,
                COALESCE(SUM(CASE WHEN a.status = :failed THEN 1 ELSE 0 END), 0)    AS failed,
                COALESCE(SUM(CASE WHEN a.status = :succeeded THEN p.amount ELSE 0 END), 0) AS paid_volume
             FROM payment_attempts a
             INNER JOIN payments p ON p.id = a.payment_id
             INNER JOIN gateways g ON g.id = a.gateway_id
             WHERE %s
             GROUP BY g.id, p.currency
             ORDER BY attempts DESC',
                $where,
            ),
            $parameters + [
                'succeeded' => AttemptStatus::Succeeded->value,
                'failed' => AttemptStatus::Failed->value,
            ],
        );

        return array_map(
            static fn (Row $row): GatewayBreakdown => new GatewayBreakdown(
                $row->string('gateway_id'),
                $row->string('gateway_label'),
                $row->string('driver'),
                $row->bool('sandbox'),
                $row->int('attempts'),
                $row->int('succeeded'),
                $row->int('failed'),
                $row->int('paid_volume'),
                $row->string('currency'),
            ),
            $rows,
        );
    }

    public function topFailureReasons(TransactionFilter $filter, int $limit = 10): array
    {
        [$where, $parameters] = $this->buildWhere($filter);

        $rows = Rows::all(
            $this->pdo,
            sprintf(
                'SELECT
                COALESCE(a.failure_code, \'unknown\')    AS code,
                COALESCE(a.failure_message, \'\')        AS message,
                COUNT(*)                                AS occurrences
             FROM payment_attempts a
             INNER JOIN payments p ON p.id = a.payment_id
             WHERE %s AND a.status = :failed
             GROUP BY code
             ORDER BY occurrences DESC
             LIMIT :limit',
                $where,
            ),
            $parameters + [
                'failed' => AttemptStatus::Failed->value,
                'limit' => $limit,
            ],
        );

        return array_map(
            static fn (Row $row): FailureReason => new FailureReason(
                $row->string('code'),
                $row->string('message'),
                $row->int('occurrences'),
            ),
            $rows,
        );
    }

    public function hourlyDistribution(TransactionFilter $filter): array
    {
        [$where, $parameters] = $this->buildWhere($filter);

        $hour = sprintf(
            "CAST(strftime('%%H', %s) AS INTEGER)",
            $this->localTimestampExpression('p.created_at'),
        );

        $rows = Rows::all(
            $this->pdo,
            sprintf(
                'SELECT %s AS hour, COUNT(*) AS occurrences
                 FROM payments p
                 WHERE %s AND p.status IN (%s)
                 GROUP BY hour',
                $hour,
                $where,
                $this->quotedStatuses($this->successfulStatuses()),
            ),
            $parameters,
        );

        $distribution = array_fill(0, 24, 0);

        foreach ($rows as $row) {
            $distribution[$row->int('hour')] = $row->int('occurrences');
        }

        return $distribution;
    }

    /**
     * Maps a joined result row onto the flat DTO the reports render.
     */
    private function toTransactionRow(Row $row): TransactionRow
    {
        return new TransactionRow(
            paymentId: $row->string('id'),
            orderId: $row->string('order_id'),
            merchantName: $row->nullableString('merchant_name') ?? '',
            merchantId: $row->string('merchant_id'),
            gatewayLabel: $row->nullableString('gateway_label'),
            driver: $row->nullableString('driver'),
            status: PaymentStatus::from($row->string('status')),
            amount: Money::of($row->int('amount'), Currency::from($row->string('currency'))),
            transactionId: $row->nullableString('transaction_id'),
            reference: $row->nullableString('reference'),
            cardPan: $row->nullableString('card_pan'),
            payerEmail: $row->nullableString('payer_email'),
            payerMobile: $row->nullableString('payer_mobile'),
            failureReason: $row->nullableString('failure_reason'),
            attempts: $row->nullableInt('attempt_count') ?? 0,
            createdAt: $row->date('created_at'),
            paidAt: $row->nullableDate('paid_at'),
        );
    }

    private function rowSelect(): string
    {
        return
            'SELECT
                p.id, p.order_id, p.merchant_id, p.status, p.amount, p.currency,
                p.payer_email, p.payer_mobile, p.failure_reason, p.created_at, p.paid_at,
                m.name AS merchant_name,
                a.transaction_id, a.reference, a.card_pan,
                g.label AS gateway_label, g.driver,
                (SELECT COUNT(*) FROM payment_attempts x WHERE x.payment_id = p.id) AS attempt_count
             FROM payments p
             INNER JOIN merchants m ON m.id = p.merchant_id
             LEFT JOIN payment_attempts a ON a.id = (
                 SELECT id FROM payment_attempts t
                 WHERE t.payment_id = p.id
                 ORDER BY CASE WHEN t.status = \'succeeded\' THEN 0 ELSE 1 END, t.sequence DESC
                 LIMIT 1
             )
             LEFT JOIN gateways g ON g.id = a.gateway_id';
    }

    /**
     * @return array{string, array<string, string|int>}
     */
    private function buildWhere(TransactionFilter $filter): array
    {
        $conditions = ['1 = 1'];
        $parameters = [];

        if ($filter->merchantId !== null) {
            $conditions[] = 'p.merchant_id = :merchant_id';
            $parameters['merchant_id'] = $filter->merchantId->value;
        }

        if ($filter->gatewayId !== null) {
            $conditions[] = 'EXISTS (
                SELECT 1 FROM payment_attempts ga
                WHERE ga.payment_id = p.id AND ga.gateway_id = :gateway_id
            )';
            $parameters['gateway_id'] = $filter->gatewayId->value;
        }

        if ($filter->statuses !== []) {
            $conditions[] = sprintf(
                'p.status IN (%s)',
                $this->quotedStatuses($filter->statuses),
            );
        }

        if ($filter->from !== null) {
            $conditions[] = 'p.created_at >= :from';
            $parameters['from'] = $filter->from->format('Y-m-d H:i:s');
        }

        if ($filter->to !== null) {
            $conditions[] = 'p.created_at <= :to';
            $parameters['to'] = $filter->to->format('Y-m-d H:i:s');
        }

        if ($filter->minAmount !== null) {
            $conditions[] = 'p.amount >= :min_amount';
            $parameters['min_amount'] = $filter->minAmount;
        }

        if ($filter->maxAmount !== null) {
            $conditions[] = 'p.amount <= :max_amount';
            $parameters['max_amount'] = $filter->maxAmount;
        }

        if ($filter->search !== null && trim($filter->search) !== '') {
            $conditions[] = '(
                p.order_id = :exact_search
                OR p.id = :exact_search
                OR p.payer_email LIKE :search
                OR p.payer_mobile LIKE :search
                OR EXISTS (
                    SELECT 1 FROM payment_attempts sa
                    WHERE sa.payment_id = p.id
                      AND (sa.reference = :exact_search OR sa.transaction_id = :exact_search)
                )
            )';
            $parameters['exact_search'] = trim($filter->search);
            $parameters['search'] = '%' . trim($filter->search) . '%';
        }

        return [implode(' AND ', $conditions), $parameters];
    }

    /**
     * Renders a status list as a quoted SQL list.
     *
     * Safe because the values come from a PHP enum, never from user input —
     * placeholders cannot be used for a variable-length IN list without
     * rebuilding the parameter set for every call.
     *
     * @param list<PaymentStatus> $statuses
     */
    private function quotedStatuses(array $statuses): string
    {
        return implode(', ', array_map(
            static fn (PaymentStatus $status): string => "'" . $status->value . "'",
            $statuses,
        ));
    }

    /** @return list<PaymentStatus> */
    private function successfulStatuses(): array
    {
        return [PaymentStatus::Paid, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded];
    }

    /** @return list<PaymentStatus> */
    private function failedStatuses(): array
    {
        return [PaymentStatus::Failed, PaymentStatus::Canceled, PaymentStatus::Expired];
    }

    /** @return list<PaymentStatus> */
    private function openStatuses(): array
    {
        return [PaymentStatus::Created, PaymentStatus::Pending, PaymentStatus::AwaitingVerification];
    }

    private function localDayExpression(string $column): string
    {
        return sprintf("date(%s)", $this->localTimestampExpression($column));
    }

    /**
     * SQLite has no timezone database, so the offset is resolved in PHP and
     * embedded as a literal modifier. It is recomputed per request, which keeps
     * daylight saving correct for the zones that observe it.
     */
    private function localTimestampExpression(string $column): string
    {
        $offset = (new DateTimeZone($this->displayTimezone))
            ->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC')));

        $sign = $offset >= 0 ? '+' : '-';
        $absolute = abs($offset);

        return sprintf(
            "datetime(%s, '%s%d hours', '%s%d minutes')",
            $column,
            $sign,
            intdiv($absolute, 3600),
            $sign,
            intdiv($absolute % 3600, 60),
        );
    }
}
