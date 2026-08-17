<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Reporting\TransactionFilter;
use App\Domain\Gateway\GatewayId;
use App\Domain\Merchant\MerchantId;
use App\Domain\Payment\PaymentStatus;
use App\Presentation\Support\DateFormatter;
use App\Presentation\Support\PanelContext;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds a {@see TransactionFilter} from query parameters.
 *
 * The date fields go through {@see DateFormatter}, so a filter typed as
 * "1403/05/26" in Jalali mode and "2024-08-16" in Gregorian mode produce
 * exactly the same UTC range. Getting this wrong is how reports quietly cover
 * the wrong period, so it lives in one tested place.
 */
final readonly class TransactionFilterFactory
{
    public function __construct(
        private DateFormatter $dates,
        private PanelContext $context,
    ) {
    }

    public function fromRequest(ServerRequestInterface $request): TransactionFilter
    {
        $query = $request->getQueryParams();

        return new TransactionFilter(
            merchantId: $this->merchantId($query),
            gatewayId: $this->gatewayId($query),
            statuses: $this->statuses($query),
            from: $this->dates->parseStartOfDay($this->string($query, 'from')),
            to: $this->dates->parseEndOfDay($this->string($query, 'to')),
            minAmount: $this->amount($query, 'min_amount'),
            maxAmount: $this->amount($query, 'max_amount'),
            search: $this->string($query, 'q'),
            page: max(1, (int) ($query['page'] ?? 1)),
            perPage: $this->context->pageSize(),
        );
    }

    /**
     * A filter defaulting to the last 30 days, for the report pages where an
     * unbounded query would be neither useful nor fast.
     */
    public function withDefaultPeriod(ServerRequestInterface $request): TransactionFilter
    {
        $filter = $this->fromRequest($request);

        if ($filter->from !== null || $filter->to !== null) {
            return $filter;
        }

        return new TransactionFilter(
            merchantId: $filter->merchantId,
            gatewayId: $filter->gatewayId,
            statuses: $filter->statuses,
            from: new DateTimeImmutable('-30 days', new DateTimeZone('UTC')),
            to: null,
            minAmount: $filter->minAmount,
            maxAmount: $filter->maxAmount,
            search: $filter->search,
            page: $filter->page,
            perPage: $filter->perPage,
        );
    }

    /**
     * @param array<string, mixed> $query
     * @return list<PaymentStatus>
     */
    private function statuses(array $query): array
    {
        $raw = $query['status'] ?? null;

        if (is_string($raw)) {
            $raw = $raw === '' ? [] : [$raw];
        }

        if (!is_array($raw)) {
            return [];
        }

        $statuses = [];

        foreach ($raw as $value) {
            $status = is_string($value) ? PaymentStatus::tryFrom($value) : null;

            if ($status !== null) {
                $statuses[] = $status;
            }
        }

        return $statuses;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function merchantId(array $query): ?MerchantId
    {
        $value = $this->string($query, 'merchant');

        if ($value === null) {
            return null;
        }

        try {
            return MerchantId::fromString($value);
        } catch (InvalidArgumentException) {
            // A hand-edited query string should narrow nothing, not explode.
            return null;
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    private function gatewayId(array $query): ?GatewayId
    {
        $value = $this->string($query, 'gateway');

        if ($value === null) {
            return null;
        }

        try {
            return GatewayId::fromString($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    private function string(array $query, string $key): ?string
    {
        $value = $query[$key] ?? null;

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function amount(array $query, string $key): ?int
    {
        $value = $this->string($query, $key);

        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $value) ?? '';

        return $digits === '' ? null : (int) $digits;
    }
}
