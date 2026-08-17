<?php

declare(strict_types=1);

namespace App\Application\Reporting;

/**
 * The read side of the system.
 *
 * Kept apart from the write-side repositories on purpose: reports want wide,
 * pre-joined, aggregated rows, and forcing them through aggregates would make
 * both sides worse.
 */
interface ReportingRepository
{
    /** @return Paginated<TransactionRow> */
    public function search(TransactionFilter $filter): Paginated;

    /** @return list<TransactionRow> */
    public function export(TransactionFilter $filter): array;

    public function summarise(TransactionFilter $filter): Summary;

    /**
     * Day-by-day totals, for the trend chart and the daily report.
     *
     * @return list<DailyBucket>
     */
    public function dailyBreakdown(TransactionFilter $filter): array;

    /**
     * Per-merchant totals for the same period, which is the "report per site"
     * view.
     *
     * @return list<MerchantBreakdown>
     */
    public function merchantBreakdown(TransactionFilter $filter): array;

    /**
     * Per-gateway totals, used to compare PSP reliability.
     *
     * @return list<GatewayBreakdown>
     */
    public function gatewayBreakdown(TransactionFilter $filter): array;

    /**
     * The most common reasons payments fail, most frequent first.
     *
     * @return list<FailureReason>
     */
    public function topFailureReasons(TransactionFilter $filter, int $limit = 10): array;

    /**
     * Successful payment counts by hour of day (0-23), in the panel timezone.
     *
     * @return array<int, int>
     */
    public function hourlyDistribution(TransactionFilter $filter): array;
}
