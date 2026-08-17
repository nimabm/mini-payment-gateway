<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

/**
 * A thin wrapper over `$_SESSION`.
 *
 * Wrapping it keeps the superglobal out of controllers, gives PHPStan
 * something typed to check, and makes the session trivially fakeable in tests.
 */
final class Session
{
    public function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
            ]);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Reads a value and removes it — used for one-shot flash messages. */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    /**
     * @param array<string, string|int|float> $replacements Filled into the
     *        translated line, so a flash can carry a number without the message
     *        having to be assembled before it is translated.
     */
    public function flash(string $message, string $tone = 'success', array $replacements = []): void
    {
        $this->set('flash', [
            'message' => $message,
            'tone' => $tone,
            'replacements' => $replacements,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        /** @var array<string, mixed> $session */
        $session = $_SESSION ?? [];

        return $session;
    }

    /** Called on login: prevents session fixation. */
    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
    }

    /**
     * A per-session CSRF token, created on first use.
     */
    public function csrfToken(): string
    {
        $token = $this->get('csrf_token');

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->set('csrf_token', $token);
        }

        return $token;
    }

    public function verifyCsrf(?string $token): bool
    {
        $expected = $this->get('csrf_token');

        return is_string($expected)
            && is_string($token)
            && hash_equals($expected, $token);
    }
}
