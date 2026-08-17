<?php

declare(strict_types=1);

namespace App;

use App\Infrastructure\Config\ConfigurationGuard;
use App\Infrastructure\Config\MisconfiguredApplication;
use App\Presentation\Api\ApiErrorMiddleware;
use App\Presentation\Routes;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Builds the container and the HTTP application.
 *
 * Shared by the front controller, the console and the test suite, so all three
 * run against exactly the same wiring.
 */
final class Bootstrap
{
    public static function container(): ContainerInterface
    {
        self::loadEnvironment();

        // Before anything is wired: a production installation pointed at
        // localhost, or without an encryption key, must not appear to work.
        $problems = ConfigurationGuard::problems($_ENV);

        if ($problems !== []) {
            throw new MisconfiguredApplication($problems);
        }

        $builder = new ContainerBuilder();
        $builder->addDefinitions(dirname(__DIR__) . '/config/container.php');

        if (($_ENV['APP_ENV'] ?? 'production') === 'production') {
            // Compiling the container removes reflection from the hot path.
            $builder->enableCompilation(dirname(__DIR__) . '/var/cache/container');
        }

        return $builder->build();
    }

    /**
     * @return App<ContainerInterface|null>
     */
    public static function app(?ContainerInterface $container = null): App
    {
        $container ??= self::container();

        AppFactory::setContainer($container);
        $app = AppFactory::create();

        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->add(ApiErrorMiddleware::class);

        (new Routes())($app);

        return $app;
    }

    private static function loadEnvironment(): void
    {
        $root = dirname(__DIR__);

        if (is_file($root . '/.env')) {
            Dotenv::createImmutable($root)->safeLoad();
        }

        // Storage and rendering are decoupled: the process always runs in UTC
        // and the panel converts on the way out.
        date_default_timezone_set('UTC');
    }
}
