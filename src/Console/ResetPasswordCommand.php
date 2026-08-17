<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\Admin\AdminUserRepository;

/**
 * The way back in when nobody can sign in to change their password from the
 * panel.
 */
final readonly class ResetPasswordCommand implements Command
{
    public function __construct(private AdminUserRepository $users)
    {
    }

    public function __invoke(array $arguments): int
    {
        $email = $arguments[0] ?? null;

        if ($email === null) {
            fwrite(STDERR, "Usage: bin/console admin:password <email>\n");

            return 1;
        }

        $user = $this->users->findByEmail($email);

        if ($user === null) {
            fwrite(STDERR, sprintf("No admin user with the email %s.\n", $email));

            return 1;
        }

        // Generated rather than accepted as an argument: a password passed on a
        // command line lands in the shell history and in the process list.
        $password = bin2hex(random_bytes(9));

        $user->changePassword($password);
        $this->users->save($user);

        echo "Password reset.\n";
        echo sprintf("  Email:    %s\n", $user->email);
        echo sprintf("  Password: %s\n", $password);
        echo "Sign in and change it from Settings; it is not shown again.\n";

        return 0;
    }
}
