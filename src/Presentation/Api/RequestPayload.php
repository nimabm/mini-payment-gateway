<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Typed access to a decoded JSON body.
 *
 * Controllers should never touch a raw `mixed` from user input; this is the one
 * place where untyped data becomes typed data, so PHPStan can be strict about
 * everything downstream.
 */
final readonly class RequestPayload
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(private array $data)
    {
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $parsed = $request->getParsedBody();

        if (is_array($parsed)) {
            /** @var array<string, mixed> $parsed */
            return new self($parsed);
        }

        $decoded = json_decode((string) $request->getBody(), true);

        /** @var array<string, mixed> $decoded */
        $decoded = is_array($decoded) ? $decoded : [];

        return new self($decoded);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->data[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->string($key);

        return $value === '' ? null : $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->data[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->data[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
