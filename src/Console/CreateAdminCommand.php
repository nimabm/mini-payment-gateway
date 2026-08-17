<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\Admin\AdminUser;
use App\Domain\Admin\AdminUserRepository;
use App\Domain\Shared\Clock;

final readonly class CreateAdminCommand implements Command
{
    public function __construct(
        private AdminUserRepository $users,
        private Clock $clock,
    ) {
    }

    public function __invoke(array $arguments): int
    {
        $email = $arguments[0] ?? null;

        if ($email === null) {
            fwrite(STDERR, "Usage: bin/console admin:create <email> [name]\n");

            return 1;
        }

        if ($this->users->findByEmail($email) !== null) {
            fwrite(STDERR, sprintf("An admin with the email %s already exists.\n", $email));

            return 1;
        }

        // Generated rather than prompted: a password typed at a shell ends up in
        // the shell history, and a generated one is stronger anyway.
        $password = bin2hex(random_bytes(9));

        $user = AdminUser::register(
            $email,
            $arguments[1] ?? 'Administrator',
            $password,
            $this->clock->now(),
        );

        $this->users->save($user);

        echo "Admin user created.\n";
        echo sprintf("  Email:    %s\n", $email);
        echo sprintf("  Password: %s\n", $password);
        echo "Store the password now; it is not recoverable.\n";

        return 0;
    }
}
