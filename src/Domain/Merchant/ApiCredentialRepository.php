<?php

declare(strict_types=1);

namespace App\Domain\Merchant;

interface ApiCredentialRepository
{
    public function save(ApiCredential $credential): void;

    public function findByKeyId(string $keyId): ?ApiCredential;

    public function find(ApiCredentialId $id): ?ApiCredential;

    /** @return list<ApiCredential> */
    public function findForMerchant(MerchantId $merchantId): array;
}
