<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use App\Application\Settings\CalendarSystem;
use App\Application\Settings\Locale;
use DateTimeImmutable;
use SensitiveParameter;

/**
 * An operator of the admin panel.
 *
 * Each user may override the panel-wide language and calendar with their own
 * preference, so a Persian-speaking finance lead and an English-speaking
 * developer can share one installation.
 */
final class AdminUser
{
    public function __construct(
        public readonly AdminUserId $id,
        public readonly string $email,
        private string $name,
        private string $passwordHash,
        private ?Locale $locale,
        private ?CalendarSystem $calendar,
        public readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $lastLoginAt = null,
    ) {
    }

    public static function register(
        string $email,
        string $name,
        #[SensitiveParameter]
        string $plainPassword,
        DateTimeImmutable $now,
    ): self {
        return new self(
            AdminUserId::generate(),
            strtolower(trim($email)),
            $name,
            self::hashPassword($plainPassword),
            null,
            null,
            $now,
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function locale(): ?Locale
    {
        return $this->locale;
    }

    public function calendar(): ?CalendarSystem
    {
        return $this->calendar;
    }

    public function lastLoginAt(): ?DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function verifyPassword(#[SensitiveParameter] string $plainPassword): bool
    {
        return password_verify($plainPassword, $this->passwordHash);
    }

    public function changePassword(#[SensitiveParameter] string $plainPassword): void
    {
        $this->passwordHash = self::hashPassword($plainPassword);
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function setPreferences(?Locale $locale, ?CalendarSystem $calendar): void
    {
        $this->locale = $locale;
        $this->calendar = $calendar;
    }

    public function recordLogin(DateTimeImmutable $now): void
    {
        $this->lastLoginAt = $now;
    }

    private static function hashPassword(#[SensitiveParameter] string $plainPassword): string
    {
        // Argon2id: memory-hard, so a leaked hash is expensive to attack even
        // with a GPU farm.
        return password_hash($plainPassword, PASSWORD_ARGON2ID);
    }
}
