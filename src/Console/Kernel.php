<?php

declare(strict_types=1);

namespace App\Console;

use Psr\Container\ContainerInterface;
use Throwable;

/**
 * A minimal command dispatcher.
 *
 * A full console framework would be more dependency than five commands
 * justify. Commands are plain invokable classes, listed here.
 */
final readonly class Kernel
{
    /** @var array<string, class-string<Command>> */
    private const COMMANDS = [
        'migrate' => MigrateCommand::class,
        'seed' => SeedCommand::class,
        'admin:create' => CreateAdminCommand::class,
        'worker:run' => WorkerCommand::class,
        'worker:once' => WorkerOnceCommand::class,
    ];

    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * @param list<string> $argv
     * @return int Process exit code.
     */
    public function run(array $argv): int
    {
        $name = $argv[1] ?? null;

        if ($name === null || $name === 'list' || $name === '--help') {
            $this->printUsage();

            return 0;
        }

        if (!isset(self::COMMANDS[$name])) {
            fwrite(STDERR, sprintf("Unknown command \"%s\".\n\n", $name));
            $this->printUsage();

            return 1;
        }

        /** @var Command $command */
        $command = $this->container->get(self::COMMANDS[$name]);

        try {
            return $command(array_slice($argv, 2));
        } catch (Throwable $e) {
            fwrite(STDERR, sprintf("%s: %s\n", $e::class, $e->getMessage()));

            return 1;
        }
    }

    private function printUsage(): void
    {
        echo "Usage: bin/console <command> [arguments]\n\n";
        echo "Commands:\n";
        echo "  migrate         Apply pending database migrations\n";
        echo "  seed            Create an admin user, a demo website and the built-in gateways\n";
        echo "  admin:create    Create an admin user: admin:create <email> [name]\n";
        echo "  worker:run      Run the background worker loop (webhooks, reconciliation, expiry)\n";
        echo "  worker:once     Run one worker pass and exit — useful from cron\n";
    }
}
