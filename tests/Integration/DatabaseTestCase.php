<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Infrastructure\Persistence\Migrator;
use App\Infrastructure\Security\CredentialEncryptor;
use App\Tests\Support\FrozenClock;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need real storage.
 *
 * The database is a fresh in-memory SQLite built from the real migrations, not
 * a hand-written fixture schema — otherwise the tests would pass against a
 * schema that does not exist in production.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;
    protected FrozenClock $clock;
    protected CredentialEncryptor $encryptor;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        $this->pdo->exec('PRAGMA foreign_keys = ON');

        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();

        $this->clock = new FrozenClock();
        $this->encryptor = new CredentialEncryptor(base64_encode(random_bytes(32)));
    }
}
