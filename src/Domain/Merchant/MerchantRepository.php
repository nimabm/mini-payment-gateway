<?php

declare(strict_types=1);

namespace App\Domain\Merchant;

interface MerchantRepository
{
    public function save(Merchant $merchant): void;

    public function find(MerchantId $id): ?Merchant;

    public function findBySlug(string $slug): ?Merchant;

    /** @return list<Merchant> */
    public function all(): array;

    public function slugExists(string $slug): bool;
}
