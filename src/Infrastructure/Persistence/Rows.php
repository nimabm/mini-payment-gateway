<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Executes queries and hands back typed {@see Row} objects.
 *
 * `PDO::query()` returns `PDOStatement|false` and `fetchAll()` returns an array
 * of `mixed`, so every call site would otherwise repeat the same two checks.
 * They are done once, here.
 */
final readonly class Rows
{
    /**
     * @param array<string, mixed> $parameters
     * @return list<Row>
     */
    public static function all(PDO $pdo, string $sql, array $parameters = []): array
    {
        return self::fromStatement(self::execute($pdo, $sql, $parameters));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function one(PDO $pdo, string $sql, array $parameters = []): ?Row
    {
        $row = self::execute($pdo, $sql, $parameters)->fetch();

        return is_array($row) ? new Row($row) : null;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function scalar(PDO $pdo, string $sql, array $parameters = []): mixed
    {
        return self::execute($pdo, $sql, $parameters)->fetchColumn();
    }

    /**
     * A scalar known to be a count or other integer.
     *
     * @param array<string, mixed> $parameters
     */
    public static function int(PDO $pdo, string $sql, array $parameters = []): int
    {
        $value = self::scalar($pdo, $sql, $parameters);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<Row>
     */
    public static function fromStatement(PDOStatement $statement): array
    {
        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $rows[] = new Row($row);
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function execute(PDO $pdo, string $sql, array $parameters = []): PDOStatement
    {
        $statement = $pdo->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException('The database rejected the statement: ' . $sql);
        }

        $statement->execute($parameters);

        return $statement;
    }
}
