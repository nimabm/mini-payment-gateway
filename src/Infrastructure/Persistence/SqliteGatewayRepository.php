<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Gateway\DriverName;
use App\Domain\Gateway\GatewayConfig;
use App\Domain\Gateway\GatewayId;
use App\Domain\Gateway\GatewayRepository;
use App\Domain\Merchant\MerchantId;
use App\Domain\Shared\Currency;
use App\Infrastructure\Security\CredentialEncryptor;
use PDO;
use Throwable;

final readonly class SqliteGatewayRepository implements GatewayRepository
{
    public function __construct(
        private PDO $pdo,
        private CredentialEncryptor $encryptor,
    ) {
    }

    public function save(GatewayConfig $gateway): void
    {
        Rows::execute(
            $this->pdo,
            'INSERT INTO gateways (
                id, driver, label, credentials, currencies, sandbox, enabled,
                priority, min_amount, max_amount, created_at
             ) VALUES (
                :id, :driver, :label, :credentials, :currencies, :sandbox, :enabled,
                :priority, :min_amount, :max_amount, :created_at
             )
             ON CONFLICT (id) DO UPDATE SET
                label = excluded.label,
                credentials = excluded.credentials,
                currencies = excluded.currencies,
                sandbox = excluded.sandbox,
                enabled = excluded.enabled,
                priority = excluded.priority,
                min_amount = excluded.min_amount,
                max_amount = excluded.max_amount',
            [
                'id' => $gateway->id->value,
                'driver' => $gateway->driver->value,
                'label' => $gateway->label(),
                'credentials' => $this->encryptor->encrypt($gateway->credentials()),
                'currencies' => json_encode(
                    array_map(static fn (Currency $c): string => $c->value, $gateway->currencies()),
                    JSON_THROW_ON_ERROR,
                ),
                'sandbox' => $gateway->isSandbox() ? 1 : 0,
                'enabled' => $gateway->isEnabled() ? 1 : 0,
                'priority' => $gateway->priority(),
                'min_amount' => $gateway->minAmount(),
                'max_amount' => $gateway->maxAmount(),
                'created_at' => $gateway->createdAt->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function find(GatewayId $id): ?GatewayConfig
    {
        $row = Rows::one($this->pdo, 'SELECT * FROM gateways WHERE id = :value', ['value' => $id->value]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        return array_map($this->hydrate(...), Rows::all(
            $this->pdo,
            'SELECT * FROM gateways ORDER BY priority, label COLLATE NOCASE',
        ));
    }

    public function findAssignedTo(MerchantId $merchantId): array
    {
        return array_map($this->hydrate(...), Rows::all(
            $this->pdo,
            'SELECT g.*
             FROM gateways g
             INNER JOIN merchant_gateways mg ON mg.gateway_id = g.id
             WHERE mg.merchant_id = :merchant_id
             ORDER BY mg.priority, g.priority, g.label COLLATE NOCASE',
            ['merchant_id' => $merchantId->value],
        ));
    }

    public function assignToMerchant(MerchantId $merchantId, array $gatewayIds): void
    {
        $this->pdo->beginTransaction();

        try {
            Rows::execute(
                $this->pdo,
                'DELETE FROM merchant_gateways WHERE merchant_id = :merchant_id',
                ['merchant_id' => $merchantId->value],
            );

            foreach (array_values($gatewayIds) as $position => $gatewayId) {
                Rows::execute(
                    $this->pdo,
                    'INSERT INTO merchant_gateways (merchant_id, gateway_id, priority)
                     VALUES (:merchant_id, :gateway_id, :priority)',
                    [
                        'merchant_id' => $merchantId->value,
                        'gateway_id' => $gatewayId->value,
                        // The order given by the operator is the failover order.
                        'priority' => $position * 10,
                    ],
                );
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    public function assignedIds(MerchantId $merchantId): array
    {
        $rows = Rows::all(
            $this->pdo,
            'SELECT gateway_id FROM merchant_gateways WHERE merchant_id = :merchant_id ORDER BY priority',
            ['merchant_id' => $merchantId->value],
        );

        return array_map(
            static fn (Row $row): GatewayId => GatewayId::fromString($row->string('gateway_id')),
            $rows,
        );
    }

    private function hydrate(Row $row): GatewayConfig
    {
        return new GatewayConfig(
            GatewayId::fromString($row->string('id')),
            DriverName::fromString($row->string('driver')),
            $row->string('label'),
            $this->encryptor->decrypt($row->string('credentials')),
            array_map(Currency::from(...), $row->stringList('currencies')),
            $row->bool('sandbox'),
            $row->bool('enabled'),
            $row->int('priority'),
            $row->nullableInt('min_amount'),
            $row->nullableInt('max_amount'),
            $row->date('created_at'),
        );
    }
}
