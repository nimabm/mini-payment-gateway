<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Typed access to a posted form body, mirroring the API's RequestPayload.
 *
 * Same reason: `$_POST` is `mixed`, and mixed has no business reaching a
 * domain object.
 */
final readonly class FormInput
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(private array $data)
    {
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $body = $request->getParsedBody();

        /** @var array<string, mixed> $body */
        $body = is_array($body) ? $body : [];

        return new self($body);
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

    public function nullableInt(string $key): ?int
    {
        $value = $this->string($key);

        return $value === '' ? null : (int) preg_replace('/[^0-9]/', '', $value);
    }

    /** An unchecked HTML checkbox posts nothing at all, hence the default. */
    public function checkbox(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->data[$key] ?? [];

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
