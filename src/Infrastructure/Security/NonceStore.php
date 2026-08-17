<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Shared\Clock;
use PDO;
use PDOException;

/**
 * Remembers nonces long enough to make a signature single-use.
 *
 * The timestamp window bounds how long a nonce must be remembered, so the table
 * stays small: anything older than the window is pruned and can never be
 * replayed anyway, because the timestamp check rejects it first.
 */
final readonly class NonceStore
{
    public function __construct(
        private PDO $pdo,
        private Clock $clock,
    ) {
    }

    /**
     * Claims a nonce.
     *
     * @return bool False when it has already been used — the request is a replay.
     */
    public function claim(string $nonce, string $keyId): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO request_nonces (nonce, key_id, created_at) VALUES (:nonce, :key_id, :created_at)',
            );

            $statement->execute([
                'nonce' => $nonce,
                'key_id' => $keyId,
                'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (PDOException) {
            // The primary key rejected it: this exact nonce was used before.
            return false;
        }
    }

    public function prune(int $olderThanSeconds): int
    {
        $cutoff = $this->clock->now()
            ->modify(sprintf('-%d seconds', $olderThanSeconds))
            ->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare('DELETE FROM request_nonces WHERE created_at < :cutoff');
        $statement->execute(['cutoff' => $cutoff]);

        return $statement->rowCount();
    }
}
