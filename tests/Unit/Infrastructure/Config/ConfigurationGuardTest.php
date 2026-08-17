<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Config;

use App\Infrastructure\Config\ConfigurationGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigurationGuardTest extends TestCase
{
    private const string VALID_KEY = 'a2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2s=';

    public function test_a_correctly_configured_production_installation_passes(): void
    {
        self::assertSame([], ConfigurationGuard::problems([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'https://pay.example.com',
            'APP_KEY' => self::VALID_KEY,
        ]));
    }

    /**
     * The mistake this guard exists for: copying the project to a server and
     * starting it without changing APP_URL.
     */
    #[DataProvider('unreachableHosts')]
    public function test_it_rejects_an_app_url_no_bank_can_reach(string $url): void
    {
        $problems = ConfigurationGuard::problems([
            'APP_ENV' => 'production',
            'APP_URL' => $url,
            'APP_KEY' => self::VALID_KEY,
        ]);

        self::assertCount(1, $problems);
        self::assertStringContainsString('APP_URL', $problems[0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreachableHosts(): iterable
    {
        yield 'localhost' => ['http://localhost:8080'];
        yield 'loopback address' => ['http://127.0.0.1'];
        yield 'ipv6 loopback' => ['http://[::1]:8080'];
        yield 'wildcard' => ['http://0.0.0.0:8080'];
        yield 'docker host alias' => ['https://host.docker.internal'];
    }

    public function test_it_rejects_a_url_that_is_not_absolute(): void
    {
        $problems = ConfigurationGuard::problems([
            'APP_ENV' => 'production',
            'APP_URL' => 'pay.example.com',
            'APP_KEY' => self::VALID_KEY,
        ]);

        self::assertCount(1, $problems);
        self::assertStringContainsString('not a valid absolute URL', $problems[0]);
    }

    #[DataProvider('badKeys')]
    public function test_it_rejects_a_key_that_cannot_encrypt(mixed $key): void
    {
        $problems = ConfigurationGuard::problems([
            'APP_ENV' => 'production',
            'APP_URL' => 'https://pay.example.com',
            'APP_KEY' => $key,
        ]);

        self::assertCount(1, $problems);
        self::assertStringContainsString('APP_KEY', $problems[0]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'missing' => [null];
        yield 'not base64' => ['not-a-key!!'];
        yield 'too short' => [base64_encode('short')];
        yield 'too long' => [base64_encode(str_repeat('a', 64))];
    }

    public function test_it_rejects_debug_mode_in_production(): void
    {
        $problems = ConfigurationGuard::problems([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'true',
            'APP_URL' => 'https://pay.example.com',
            'APP_KEY' => self::VALID_KEY,
        ]);

        self::assertCount(1, $problems);
        self::assertStringContainsString('APP_DEBUG', $problems[0]);
    }

    public function test_it_reports_every_problem_at_once(): void
    {
        // One restart per mistake would be a miserable way to deploy.
        self::assertCount(3, ConfigurationGuard::problems([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'true',
            'APP_URL' => 'http://localhost:8080',
            'APP_KEY' => '',
        ]));
    }

    /**
     * Developing locally is the whole reason APP_URL points at localhost, so
     * none of this applies outside production.
     */
    #[DataProvider('nonProductionEnvironments')]
    public function test_it_leaves_other_environments_alone(string $environment): void
    {
        self::assertSame([], ConfigurationGuard::problems([
            'APP_ENV' => $environment,
            'APP_DEBUG' => 'true',
            'APP_URL' => 'http://localhost:8080',
            'APP_KEY' => '',
        ]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonProductionEnvironments(): iterable
    {
        yield 'local' => ['local'];
        yield 'testing' => ['testing'];
    }

    /**
     * An absent APP_ENV means production, because the unsafe default has to be
     * the one that fails loudly rather than the one that ships a stack trace.
     */
    public function test_a_missing_environment_is_treated_as_production(): void
    {
        self::assertNotSame([], ConfigurationGuard::problems([
            'APP_URL' => 'http://localhost:8080',
            'APP_KEY' => self::VALID_KEY,
        ]));
    }
}
