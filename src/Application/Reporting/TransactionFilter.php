<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use App\Domain\Gateway\GatewayId;
use App\Domain\Merchant\MerchantId;
use App\Domain\Payment\PaymentStatus;
use DateTimeImmutable;

/**
 * The criteria behind every report and the transaction list.
 *
 * One filter object serves all of them so "export what I am looking at" is
 * guaranteed to match the screen.
 */
final readonly class TransactionFilter
{
    /**
     * @param list<PaymentStatus> $statuses Empty means every status.
     */
    public function __construct(
        public ?MerchantId $merchantId = null,
        public ?GatewayId $gatewayId = null,
        public array $statuses = [],
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public ?int $minAmount = null,
        public ?int $maxAmount = null,
        /** Free text: order id, transaction id, PSP reference, email or mobile. */
        public ?string $search = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {
    }

    public function withPage(int $page): self
    {
        return new self(
            $this->merchantId,
            $this->gatewayId,
            $this->statuses,
            $this->from,
            $this->to,
            $this->minAmount,
            $this->maxAmount,
            $this->search,
            $page,
            $this->perPage,
        );
    }

    /** A copy with paging removed, for exports. */
    public function unpaginated(int $limit = 50_000): self
    {
        return new self(
            $this->merchantId,
            $this->gatewayId,
            $this->statuses,
            $this->from,
            $this->to,
            $this->minAmount,
            $this->maxAmount,
            $this->search,
            1,
            $limit,
        );
    }

    public function offset(): int
    {
        return max(0, ($this->page - 1) * $this->perPage);
    }
}
