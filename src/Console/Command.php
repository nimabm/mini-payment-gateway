<?php

declare(strict_types=1);

namespace App\Console;

interface Command
{
    /**
     * @param list<string> $arguments
     * @return int Process exit code.
     */
    public function __invoke(array $arguments): int;
}
