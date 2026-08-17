<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Settings\CalendarSystem;
use App\Application\Settings\Locale;
use App\Domain\Admin\AdminUser;
use App\Domain\Admin\AdminUserId;
use App\Domain\Admin\AdminUserRepository;
use PDO;

final readonly class SqliteAdminUserRepository implements AdminUserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function save(AdminUser $user): void
    {
        Rows::execute(
            $this->pdo,
            'INSERT INTO admin_users (
                id, email, name, password_hash, locale, calendar, created_at, last_login_at
             ) VALUES (
                :id, :email, :name, :password_hash, :locale, :calendar, :created_at, :last_login_at
             )
             ON CONFLICT (id) DO UPDATE SET
                name = excluded.name,
                password_hash = excluded.password_hash,
                locale = excluded.locale,
                calendar = excluded.calendar,
                last_login_at = excluded.last_login_at',
            [
                'id' => $user->id->value,
                'email' => $user->email,
                'name' => $user->name(),
                'password_hash' => $user->passwordHash(),
                'locale' => $user->locale()?->value,
                'calendar' => $user->calendar()?->value,
                'created_at' => $user->createdAt->format('Y-m-d H:i:s'),
                'last_login_at' => $user->lastLoginAt()?->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function find(AdminUserId $id): ?AdminUser
    {
        $row = Rows::one($this->pdo, 'SELECT * FROM admin_users WHERE id = :value', ['value' => $id->value]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByEmail(string $email): ?AdminUser
    {
        $row = Rows::one(
            $this->pdo,
            'SELECT * FROM admin_users WHERE email = :value',
            ['value' => strtolower(trim($email))],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        return array_map($this->hydrate(...), Rows::all($this->pdo, 'SELECT * FROM admin_users ORDER BY email'));
    }

    public function count(): int
    {
        return Rows::int($this->pdo, 'SELECT COUNT(*) FROM admin_users');
    }

    private function hydrate(Row $row): AdminUser
    {
        $locale = $row->nullableString('locale');
        $calendar = $row->nullableString('calendar');

        return new AdminUser(
            AdminUserId::fromString($row->string('id')),
            $row->string('email'),
            $row->string('name'),
            $row->string('password_hash'),
            $locale === null ? null : Locale::from($locale),
            $calendar === null ? null : CalendarSystem::from($calendar),
            $row->date('created_at'),
            $row->nullableDate('last_login_at'),
        );
    }
}
