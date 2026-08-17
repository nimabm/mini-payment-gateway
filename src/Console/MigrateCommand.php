<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\Persistence\Migrator;

final readonly class MigrateCommand implements Command
{
    public function __construct(private Migrator $migrator)
    {
    }

    public function __invoke(array $arguments): int
    {
        $applied = $this->migrator->migrate();

        if ($applied === []) {
            echo "Schema is up to date.\n";

            return 0;
        }

        foreach ($applied as $name) {
            echo sprintf("Applied %s\n", $name);
        }

        return 0;
    }
}
