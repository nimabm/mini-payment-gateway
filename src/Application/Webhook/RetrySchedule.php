<?php

declare(strict_types=1);

namespace App\Application\Webhook;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A fixed, explicit back-off schedule.
 *
 * Explicit beats computed: an operator reading "1, 5, 30, 120, 360, 1440"
 * knows exactly when a merchant stops being retried, which matters when
 * somebody's orders are not being marked as paid.
 */
final readonly class RetrySchedule
{
    /** @param list<int> $delaysInMinutes */
    public function __construct(private array $delaysInMinutes)
    {
        if ($delaysInMinutes === []) {
            throw new InvalidArgumentException('A retry schedule needs at least one delay.');
        }
    }

    public static function fromString(string $csv): self
    {
        $delays = array_values(array_map(
            static fn (string $value): int => (int) trim($value),
            array_filter(explode(',', $csv), static fn (string $v): bool => trim($v) !== ''),
        ));

        return new self($delays);
    }

    public function maxAttempts(): int
    {
        return count($this->delaysInMinutes);
    }

    /**
     * @param int $attemptsMade Attempts already made, including the one that just failed.
     * @return DateTimeImmutable|null Null when the schedule is exhausted.
     */
    public function nextAttemptAfter(int $attemptsMade, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($attemptsMade >= $this->maxAttempts()) {
            return null;
        }

        return $now->modify(sprintf('+%d minutes', $this->delaysInMinutes[$attemptsMade]));
    }
}
