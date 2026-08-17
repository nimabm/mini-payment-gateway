<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Settings\SettingKey;
use App\Application\Settings\Settings;
use App\Domain\Gateway\GatewayConfig;
use App\Domain\Gateway\GatewayId;
use App\Domain\Gateway\GatewayRepository;
use App\Domain\Merchant\MerchantId;

/**
 * Applies the global "force sandbox" switch to every gateway that is read.
 *
 * This exists for one scenario that happens to everyone eventually: a staging
 * environment restored from a production database backup, complete with live
 * PSP credentials, one careless click away from charging real cards. Flipping
 * one setting makes every gateway behave as a sandbox connection, whatever its
 * own configuration says.
 *
 * A decorator rather than a branch inside the repository, so the rule is
 * visible in the wiring and trivially removable.
 */
final readonly class SandboxEnforcingGatewayRepository implements GatewayRepository
{
    public function __construct(
        private GatewayRepository $inner,
        private Settings $settings,
    ) {
    }

    public function save(GatewayConfig $gateway): void
    {
        // Writes pass through untouched: the switch must never rewrite an
        // operator's stored intent, only override how it is used.
        $this->inner->save($gateway);
    }

    public function find(GatewayId $id): ?GatewayConfig
    {
        $gateway = $this->inner->find($id);

        return $gateway === null ? null : $this->applyOverride($gateway);
    }

    public function all(): array
    {
        return array_map($this->applyOverride(...), $this->inner->all());
    }

    public function findAssignedTo(MerchantId $merchantId): array
    {
        return array_map($this->applyOverride(...), $this->inner->findAssignedTo($merchantId));
    }

    public function assignToMerchant(MerchantId $merchantId, array $gatewayIds): void
    {
        $this->inner->assignToMerchant($merchantId, $gatewayIds);
    }

    public function assignedIds(MerchantId $merchantId): array
    {
        return $this->inner->assignedIds($merchantId);
    }

    public function isForced(): bool
    {
        return $this->settings->get(SettingKey::FORCE_SANDBOX, '0') === '1';
    }

    private function applyOverride(GatewayConfig $gateway): GatewayConfig
    {
        if ($this->isForced()) {
            $gateway->setSandbox(true);
        }

        return $gateway;
    }
}
