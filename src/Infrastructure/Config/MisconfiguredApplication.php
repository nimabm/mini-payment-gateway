<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

use RuntimeException;

final class MisconfiguredApplication extends RuntimeException
{
    /**
     * @param list<string> $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(
            "This installation is not configured to run in production:\n\n  - "
            . implode("\n\n  - ", $problems),
        );
    }
}
