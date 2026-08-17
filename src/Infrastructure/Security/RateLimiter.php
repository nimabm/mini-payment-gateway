<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Shared\Clock;
use PDO;

/**
 * Fixed-window rate limiting, one row per bucket per minute.
 *
 * A fixed window lets a caller burst across a boundary, which is an acceptable
 * trade here: the goal is to stop runaway loops and brute force, not to shape
 * traffic to the millisecond.
 */
final readonly class RateLimiter
{
    public function __construct(
        private PDO $pdo,
        private Clock $clock,
    ) {
    }

    /**
     * @return bool True when the request is within the limit.
     */
    public function allow(string $bucket, int $limitPerMinute): bool
    {
        if ($limitPerMinute <= 0) {
            return true;
        }

        $window = $this->clock->now()->format('Y-m-d H:i');

        $statement = $this->pdo->prepare(
            'INSERT INTO rate_limits (bucket, window_at, hits) VALUES (:bucket, :window_at, 1)
             ON CONFLICT (bucket, window_at) DO UPDATE SET hits = hits + 1',
        );
        $statement->execute(['bucket' => $bucket, 'window_at' => $window]);

        $current = $this->pdo->prepare(
            'SELECT hits FROM rate_limits WHERE bucket = :bucket AND window_at = :window_at',
        );
        $current->execute(['bucket' => $bucket, 'window_at' => $window]);

        return (int) $current->fetchColumn() <= $limitPerMinute;
    }

    public function prune(int $olderThanMinutes = 60): int
    {
        $cutoff = $this->clock->now()
            ->modify(sprintf('-%d minutes', $olderThanMinutes))
            ->format('Y-m-d H:i');

        $statement = $this->pdo->prepare('DELETE FROM rate_limits WHERE window_at < :cutoff');
        $statement->execute(['cutoff' => $cutoff]);

        return $statement->rowCount();
    }
}
