<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

/**
 * Refuses to let the application start in production with configuration that
 * would quietly break payments or expose the installation.
 *
 * Every rule here describes a mistake that is invisible until money is moving:
 * the application would boot, the panel would work, payments would be created —
 * and then payers would be stranded, or a stack trace would be served to the
 * internet. Failing at boot turns a silent production incident into an obvious
 * deployment failure.
 *
 * Only production is checked. A developer running locally has `APP_URL` pointed
 * at localhost on purpose.
 */
final readonly class ConfigurationGuard
{
    /**
     * Hosts that can never be reached by a bank's redirect, or by the payer's
     * browser once it leaves the machine the gateway runs on.
     */
    private const array UNREACHABLE_HOSTS = [
        'localhost',
        '127.0.0.1',
        '::1',
        '0.0.0.0',
        'host.docker.internal',
    ];

    /**
     * @param array<string, mixed> $env
     * @return list<string> Empty when the configuration is usable.
     */
    public static function problems(array $env): array
    {
        if (self::string($env, 'APP_ENV', 'production') !== 'production') {
            return [];
        }

        $problems = [];

        $key = base64_decode(self::string($env, 'APP_KEY'), true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            $problems[] = 'APP_KEY is missing or malformed. It must be 32 random bytes, '
                . 'base64 encoded — generate one with `make key`. It encrypts your gateway '
                . 'credentials, so set it once and never change it.';
        }

        $url = self::string($env, 'APP_URL');
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            $problems[] = sprintf(
                'APP_URL ("%s") is not a valid absolute URL. It must be the public address '
                . 'of this service, for example https://pay.example.com',
                $url,
            );
        } elseif (in_array(self::normaliseHost($host), self::UNREACHABLE_HOSTS, true)) {
            $problems[] = sprintf(
                'APP_URL points at "%s", which no bank can reach. It is the address payment '
                . 'providers send payers back to, so every payment would be charged and none '
                . 'would be confirmed. Set it to the public URL of this service.',
                $host,
            );
        }

        if (filter_var($env['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)) {
            $problems[] = 'APP_DEBUG is on in production, which serves stack traces — '
                . 'including configuration and query fragments — to anyone who triggers an '
                . 'error. Set APP_DEBUG=false.';
        }

        return $problems;
    }

    /**
     * `parse_url` keeps the brackets around an IPv6 literal, so `[::1]` would
     * otherwise slip past the list.
     */
    private static function normaliseHost(string $host): string
    {
        return strtolower(trim($host, '[]'));
    }

    /**
     * @param array<string, mixed> $env
     */
    private static function string(array $env, string $key, string $default = ''): string
    {
        $value = $env[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }
}
