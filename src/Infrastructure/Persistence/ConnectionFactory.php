<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

/**
 * Builds the one PDO connection the application uses.
 *
 * The PRAGMAs are not optional decoration. Without them SQLite silently ignores
 * foreign keys, serialises every reader behind a single writer, and fails
 * instantly on contention instead of waiting.
 */
final readonly class ConnectionFactory
{
    public function __construct(private string $path)
    {
    }

    public function create(): PDO
    {
        $isMemory = $this->path === ':memory:';

        if (!$isMemory) {
            $directory = dirname($this->path);

            if (!is_dir($directory)) {
                mkdir($directory, 0o775, true);
            }
        }

        $pdo = new PDO(
            'sqlite:' . $this->path,
            null,
            null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ],
        );

        // Referential integrity is off by default in SQLite.
        $pdo->exec('PRAGMA foreign_keys = ON');

        // Wait rather than fail when another process holds the write lock.
        $pdo->exec('PRAGMA busy_timeout = 5000');

        if (!$isMemory) {
            // Readers no longer block the writer, which is what makes SQLite
            // viable for a gateway that reports while it takes payments.
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
        }

        return $pdo;
    }
}
