<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use Throwable;

/**
 * A deliberately small forward-only migration runner.
 *
 * Migrations are plain `.sql` files named `NNN_description.sql`. Applied ones
 * are recorded, each runs inside a transaction, and the whole thing is
 * idempotent — so the container entrypoint can call it on every boot.
 */
final readonly class Migrator
{
    public function __construct(
        private PDO $pdo,
        private string $directory,
    ) {
    }

    /**
     * @return list<string> Names of the migrations applied by this call.
     */
    public function migrate(): array
    {
        $this->ensureLedgerExists();

        $applied = $this->appliedMigrations();
        $ran = [];

        foreach ($this->availableMigrations() as $name => $path) {
            if (in_array($name, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($path);

            if ($sql === false) {
                throw new RuntimeException(sprintf('Migration "%s" could not be read.', $name));
            }

            $this->pdo->beginTransaction();

            try {
                $this->pdo->exec($sql);

                $statement = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (name, applied_at) VALUES (:name, :applied_at)',
                );
                $statement->execute([
                    'name' => $name,
                    'applied_at' => gmdate('Y-m-d H:i:s'),
                ]);

                $this->pdo->commit();
            } catch (Throwable $e) {
                $this->pdo->rollBack();

                throw new RuntimeException(
                    sprintf('Migration "%s" failed: %s', $name, $e->getMessage()),
                    0,
                    $e,
                );
            }

            $ran[] = $name;
        }

        return $ran;
    }

    /** @return list<string> */
    public function pending(): array
    {
        $this->ensureLedgerExists();

        $applied = $this->appliedMigrations();

        return array_values(array_filter(
            array_keys($this->availableMigrations()),
            static fn (string $name): bool => !in_array($name, $applied, true),
        ));
    }

    private function ensureLedgerExists(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                name TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )',
        );
    }

    /** @return list<string> */
    private function appliedMigrations(): array
    {
        $names = [];

        foreach (Rows::all($this->pdo, 'SELECT name FROM schema_migrations') as $row) {
            $names[] = $row->string('name');
        }

        return $names;
    }

    /** @return array<string, string> Migration name => absolute path, in order. */
    private function availableMigrations(): array
    {
        $files = glob(rtrim($this->directory, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $migrations = [];

        foreach ($files as $file) {
            $migrations[basename($file, '.sql')] = $file;
        }

        return $migrations;
    }
}
