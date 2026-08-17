<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Settings\Settings;
use App\Domain\Shared\Clock;
use PDO;

/**
 * Settings backed by a key/value table, read through once per request.
 *
 * Settings are consulted on nearly every page render, so the in-memory cache
 * matters; it is per-request only, which keeps the worker and the web process
 * from serving each other stale values.
 */
final class SqliteSettings implements Settings
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, string $value): void
    {
        Rows::execute(
            $this->pdo,
            'INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at)
             ON CONFLICT (key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            [
                'key' => $key,
                'value' => $value,
                'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );

        $this->cache = null;
    }

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $settings = [];

        foreach (Rows::all($this->pdo, 'SELECT key, value FROM settings') as $row) {
            $settings[$row->string('key')] = $row->string('value');
        }

        return $this->cache = $settings;
    }
}
