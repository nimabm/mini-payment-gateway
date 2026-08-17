<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Infrastructure\Config\MisconfiguredApplication;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    Bootstrap::app()->run();
} catch (MisconfiguredApplication $e) {
    // The detail goes to the operator through the container logs. The visitor
    // gets nothing: which environment variable is wrong is not the internet's
    // business, and the installation is refusing to serve anyway.
    error_log($e->getMessage());

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This installation is not configured correctly.\n";
    echo "The details are in the server log: docker compose logs app\n";
}
