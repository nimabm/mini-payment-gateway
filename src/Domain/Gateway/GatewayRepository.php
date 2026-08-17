<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use App\Domain\Merchant\MerchantId;

interface GatewayRepository
{
    public function save(GatewayConfig $gateway): void;

    public function find(GatewayId $id): ?GatewayConfig;

    /** @return list<GatewayConfig> */
    public function all(): array;

    /**
     * Gateways this merchant is permitted to use, ordered by the merchant
     * specific priority and then by the gateway's own priority.
     *
     * @return list<GatewayConfig>
     */
    public function findAssignedTo(MerchantId $merchantId): array;

    /**
     * Replaces a merchant's gateway assignments.
     *
     * @param list<GatewayId> $gatewayIds In the order they should be tried.
     */
    public function assignToMerchant(MerchantId $merchantId, array $gatewayIds): void;

    /** @return list<GatewayId> */
    public function assignedIds(MerchantId $merchantId): array;
}
