<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Admin\AdminUser;
use App\Domain\Admin\WeakPassword;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AdminUserTest extends TestCase
{
    private const string STRONG = 'correct-horse-battery-staple';

    public function test_a_registered_user_can_verify_their_password(): void
    {
        $user = $this->register();

        self::assertTrue($user->verifyPassword(self::STRONG));
        self::assertFalse($user->verifyPassword('something else entirely'));
    }

    public function test_the_password_is_never_stored_in_the_clear(): void
    {
        $user = $this->register();

        self::assertStringNotContainsString(self::STRONG, $user->passwordHash());
        self::assertStringStartsWith('$argon2id$', $user->passwordHash());
    }

    public function test_changing_the_password_invalidates_the_old_one(): void
    {
        $user = $this->register();

        $user->changePassword('a-completely-different-one');

        self::assertFalse($user->verifyPassword(self::STRONG));
        self::assertTrue($user->verifyPassword('a-completely-different-one'));
    }

    public function test_a_short_password_is_refused_on_change(): void
    {
        $user = $this->register();

        $this->expectException(WeakPassword::class);

        $user->changePassword('short');
    }

    public function test_a_short_password_is_refused_on_registration(): void
    {
        $this->expectException(WeakPassword::class);

        AdminUser::register('admin@example.com', 'Admin', 'short', new DateTimeImmutable());
    }

    /**
     * Multi-byte passwords are counted in characters, not bytes — otherwise a
     * Persian password would clear the bar with a third of the characters.
     */
    public function test_length_is_measured_in_characters(): void
    {
        $this->expectException(WeakPassword::class);

        AdminUser::register('admin@example.com', 'Admin', 'رمزعبورمن', new DateTimeImmutable());
    }

    public function test_a_failed_change_leaves_the_old_password_working(): void
    {
        $user = $this->register();

        try {
            $user->changePassword('short');
        } catch (WeakPassword) {
            // Expected.
        }

        self::assertTrue($user->verifyPassword(self::STRONG));
    }

    private function register(): AdminUser
    {
        return AdminUser::register(
            'admin@example.com',
            'Admin',
            self::STRONG,
            new DateTimeImmutable('2026-01-01 00:00:00'),
        );
    }
}
