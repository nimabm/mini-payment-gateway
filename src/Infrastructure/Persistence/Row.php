<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Typed access to one database row.
 *
 * A PDO row is an array of `mixed`, and scattering casts through every
 * repository makes the moment untyped data becomes typed data invisible. This
 * is the storage-side counterpart of `RequestPayload` and `FormInput`: one
 * place where that conversion happens, and one place to look when a column's
 * type is in doubt.
 */
final readonly class Row
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function from(array $data): self
    {
        return new self($data);
    }

    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;

        if (!is_scalar($value)) {
            throw new RuntimeException(sprintf('Column "%s" is not a string.', $key));
        }

        return (string) $value;
    }

    public function nullableString(string $key): ?string
    {
        return ($this->data[$key] ?? null) === null ? null : $this->string($key);
    }

    public function int(string $key): int
    {
        $value = $this->data[$key] ?? null;

        if (!is_numeric($value)) {
            throw new RuntimeException(sprintf('Column "%s" is not numeric.', $key));
        }

        return (int) $value;
    }

    public function nullableInt(string $key): ?int
    {
        return ($this->data[$key] ?? null) === null ? null : $this->int($key);
    }

    /** SQLite has no boolean type; 0 and 1 are the convention used throughout. */
    public function bool(string $key): bool
    {
        return (bool) ($this->data[$key] ?? false);
    }

    /** Timestamps are stored as UTC text and always come back as UTC. */
    public function date(string $key): DateTimeImmutable
    {
        return new DateTimeImmutable($this->string($key), new DateTimeZone('UTC'));
    }

    public function nullableDate(string $key): ?DateTimeImmutable
    {
        return ($this->data[$key] ?? null) === null ? null : $this->date($key);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function json(string $key): array
    {
        $raw = $this->nullableString($key);

        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $values = [];

        foreach ($this->json($key) as $value) {
            if (is_scalar($value)) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }
}
