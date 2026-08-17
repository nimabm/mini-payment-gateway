<?php

declare(strict_types=1);

namespace App\Domain\Admin;

interface AdminUserRepository
{
    public function save(AdminUser $user): void;

    public function find(AdminUserId $id): ?AdminUser;

    public function findByEmail(string $email): ?AdminUser;

    /** @return list<AdminUser> */
    public function all(): array;

    public function count(): int;
}
