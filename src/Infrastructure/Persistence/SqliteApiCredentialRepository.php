<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Merchant\ApiCredential;
use App\Domain\Merchant\ApiCredentialId;
use App\Domain\Merchant\ApiCredentialRepository;
use App\Domain\Merchant\MerchantId;
use App\Infrastructure\Security\CredentialEncryptor;
use PDO;

/**
 * Persists API credentials, encrypting the shared secret on the way in and
 * decrypting it on the way out. The domain object only ever sees plaintext;
 * the database only ever sees ciphertext.
 */
final readonly class SqliteApiCredentialRepository implements ApiCredentialRepository
{
    public function __construct(
        private PDO $pdo,
        private CredentialEncryptor $encryptor,
    ) {
    }

    public function save(ApiCredential $credential): void
    {
        Rows::execute(
            $this->pdo,
            'INSERT INTO api_credentials (
                id, merchant_id, key_id, secret_enc, label, created_at, last_used_at, revoked_at
             ) VALUES (
                :id, :merchant_id, :key_id, :secret_enc, :label, :created_at, :last_used_at, :revoked_at
             )
             ON CONFLICT (id) DO UPDATE SET
                label = excluded.label,
                last_used_at = excluded.last_used_at,
                revoked_at = excluded.revoked_at',
            [
                'id' => $credential->id->value,
                'merchant_id' => $credential->merchantId->value,
                'key_id' => $credential->keyId,
                'secret_enc' => $this->encryptor->encrypt(['secret' => $credential->secret]),
                'label' => $credential->label(),
                'created_at' => $credential->createdAt->format('Y-m-d H:i:s'),
                'last_used_at' => $credential->lastUsedAt()?->format('Y-m-d H:i:s'),
                'revoked_at' => $credential->revokedAt()?->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function findByKeyId(string $keyId): ?ApiCredential
    {
        $row = Rows::one(
            $this->pdo,
            'SELECT * FROM api_credentials WHERE key_id = :value',
            ['value' => $keyId],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function find(ApiCredentialId $id): ?ApiCredential
    {
        $row = Rows::one(
            $this->pdo,
            'SELECT * FROM api_credentials WHERE id = :value',
            ['value' => $id->value],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function findForMerchant(MerchantId $merchantId): array
    {
        return array_map($this->hydrate(...), Rows::all(
            $this->pdo,
            'SELECT * FROM api_credentials WHERE merchant_id = :merchant_id ORDER BY created_at DESC',
            ['merchant_id' => $merchantId->value],
        ));
    }

    private function hydrate(Row $row): ApiCredential
    {
        $decrypted = $this->encryptor->decrypt($row->string('secret_enc'));

        return new ApiCredential(
            ApiCredentialId::fromString($row->string('id')),
            MerchantId::fromString($row->string('merchant_id')),
            $row->string('key_id'),
            $decrypted['secret'] ?? '',
            $row->string('label'),
            $row->date('created_at'),
            $row->nullableDate('last_used_at'),
            $row->nullableDate('revoked_at'),
        );
    }
}
