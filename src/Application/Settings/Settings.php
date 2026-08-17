<?php

declare(strict_types=1);

namespace App\Application\Settings;

/**
 * Operator-editable configuration.
 *
 * Deliberately separate from environment variables: `.env` holds what the
 * deployment needs to boot, this holds what an administrator may change at
 * runtime from the panel without a redeploy.
 */
interface Settings
{
    public function get(string $key, ?string $default = null): ?string;

    public function set(string $key, string $value): void;

    /** @return array<string, string> */
    public function all(): array;
}
