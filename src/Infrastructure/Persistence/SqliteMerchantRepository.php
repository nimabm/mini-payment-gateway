<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Merchant\Merchant;
use App\Domain\Merchant\MerchantId;
use App\Domain\Merchant\MerchantRepository;
use App\Domain\Merchant\MerchantStatus;
use App\Domain\Shared\Currency;
use PDO;

final readonly class SqliteMerchantRepository implements MerchantRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function save(Merchant $merchant): void
    {
        Rows::execute(
            $this->pdo,
            'INSERT INTO merchants (
                id, name, slug, status, default_currency, webhook_url,
                allowed_callback_hosts, ip_allowlist, created_at
             ) VALUES (
                :id, :name, :slug, :status, :default_currency, :webhook_url,
                :allowed_callback_hosts, :ip_allowlist, :created_at
             )
             ON CONFLICT (id) DO UPDATE SET
                name = excluded.name,
                status = excluded.status,
                default_currency = excluded.default_currency,
                webhook_url = excluded.webhook_url,
                allowed_callback_hosts = excluded.allowed_callback_hosts,
                ip_allowlist = excluded.ip_allowlist',
            [
                'id' => $merchant->id->value,
                'name' => $merchant->name(),
                'slug' => $merchant->slug(),
                'status' => $merchant->status()->value,
                'default_currency' => $merchant->defaultCurrency()->value,
                'webhook_url' => $merchant->webhookUrl(),
                'allowed_callback_hosts' => json_encode($merchant->allowedCallbackHosts(), JSON_THROW_ON_ERROR),
                'ip_allowlist' => json_encode($merchant->ipAllowlist(), JSON_THROW_ON_ERROR),
                'created_at' => $merchant->createdAt->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function find(MerchantId $id): ?Merchant
    {
        $row = Rows::one($this->pdo, 'SELECT * FROM merchants WHERE id = :value', ['value' => $id->value]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findBySlug(string $slug): ?Merchant
    {
        $row = Rows::one($this->pdo, 'SELECT * FROM merchants WHERE slug = :value', ['value' => $slug]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        return array_map(
            $this->hydrate(...),
            Rows::all($this->pdo, 'SELECT * FROM merchants ORDER BY name COLLATE NOCASE'),
        );
    }

    public function slugExists(string $slug): bool
    {
        return Rows::scalar($this->pdo, 'SELECT 1 FROM merchants WHERE slug = :slug', ['slug' => $slug]) !== false;
    }

    private function hydrate(Row $row): Merchant
    {
        return new Merchant(
            MerchantId::fromString($row->string('id')),
            $row->string('name'),
            $row->string('slug'),
            MerchantStatus::from($row->string('status')),
            Currency::from($row->string('default_currency')),
            $row->nullableString('webhook_url'),
            $row->stringList('allowed_callback_hosts'),
            $row->stringList('ip_allowlist'),
            $row->date('created_at'),
        );
    }
}
