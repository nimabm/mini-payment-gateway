<?php

declare(strict_types=1);

use App\Application\Gateway\DriverRegistry;
use App\Application\Gateway\GatewayRouter;
use App\Application\Payment\CreatePaymentHandler;
use App\Application\Payment\ExpirePaymentsHandler;
use App\Application\Payment\ReconcilePaymentsHandler;
use App\Application\Payment\SettlePaymentHandler;
use App\Application\Payment\StartCheckoutHandler;
use App\Application\Reporting\ReportingRepository;
use App\Application\Settings\SettingKey;
use App\Application\Settings\Settings;
use App\Application\Shared\UrlBuilder;
use App\Application\Webhook\ProcessWebhookQueueHandler;
use App\Application\Webhook\RetrySchedule;
use App\Application\Webhook\WebhookPayloadFactory;
use App\Application\Webhook\WebhookPublisher;
use App\Application\Webhook\WebhookSender;
use App\Domain\Admin\AdminUserRepository;
use App\Domain\Gateway\GatewayRepository;
use App\Domain\Merchant\ApiCredentialRepository;
use App\Domain\Merchant\MerchantRepository;
use App\Domain\Payment\PaymentRepository;
use App\Domain\Shared\Clock;
use App\Domain\Webhook\WebhookRepository;
use App\Infrastructure\Clock\SystemClock;
use App\Infrastructure\Gateway\ContainerDriverRegistry;
use App\Infrastructure\Persistence\ConnectionFactory;
use App\Infrastructure\Persistence\Migrator;
use App\Infrastructure\Persistence\SandboxEnforcingGatewayRepository;
use App\Infrastructure\Persistence\SqliteAdminUserRepository;
use App\Infrastructure\Persistence\SqliteApiCredentialRepository;
use App\Infrastructure\Persistence\SqliteGatewayRepository;
use App\Infrastructure\Persistence\SqliteMerchantRepository;
use App\Infrastructure\Persistence\SqlitePaymentRepository;
use App\Infrastructure\Persistence\SqliteReportingRepository;
use App\Infrastructure\Persistence\SqliteSettings;
use App\Infrastructure\Persistence\SqliteWebhookRepository;
use App\Infrastructure\Security\CredentialEncryptor;
use App\Infrastructure\Security\NonceStore;
use App\Infrastructure\Security\RateLimiter;
use App\Infrastructure\Security\RequestSigner;
use App\Infrastructure\Webhook\HttpWebhookSender;
use App\Presentation\Api\ApiAuthenticationMiddleware;
use App\Presentation\Api\ApiErrorMiddleware;
use App\Presentation\Support\AppTwigExtension;
use App\Presentation\Support\DateFormatter;
use App\Presentation\Support\PanelContext;
use App\Presentation\Support\Translator;

use function DI\autowire;
use function DI\get;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The composition root.
 *
 * Every concrete choice the application makes is visible here and nowhere else:
 * which database, which drivers, which retry policy. Swapping any of them is a
 * change to this file alone.
 */
return [
    'settings' => require __DIR__ . '/settings.php',

    // ---------------------------------------------------------------------
    // Infrastructure primitives
    // ---------------------------------------------------------------------
    Clock::class => autowire(SystemClock::class),

    PDO::class => static function (ContainerInterface $c): PDO {
        /** @var array{database: array{path: string}} $settings */
        $settings = $c->get('settings');

        return (new ConnectionFactory($settings['database']['path']))->create();
    },

    Migrator::class => static function (ContainerInterface $c): Migrator {
        /** @var array{database: array{migrations: string}} $settings */
        $settings = $c->get('settings');

        return new Migrator($c->get(PDO::class), $settings['database']['migrations']);
    },

    CredentialEncryptor::class => static function (ContainerInterface $c): CredentialEncryptor {
        /** @var array{app: array{key: string}} $settings */
        $settings = $c->get('settings');

        return new CredentialEncryptor($settings['app']['key']);
    },

    LoggerInterface::class => static function (ContainerInterface $c): LoggerInterface {
        /** @var array{logging: array{level: string, path: string}} $settings */
        $settings = $c->get('settings');

        $logger = new Logger('gateway');
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushHandler(new StreamHandler(
            $settings['logging']['path'],
            Level::fromName($settings['logging']['level']),
        ));

        return $logger;
    },

    ClientInterface::class => static fn (): ClientInterface => new Client([
        'timeout' => 20,
        'connect_timeout' => 5,
    ]),

    UrlBuilder::class => static function (ContainerInterface $c): UrlBuilder {
        /** @var array{app: array{url: string}} $settings */
        $settings = $c->get('settings');

        return new UrlBuilder($settings['app']['url']);
    },

    // ---------------------------------------------------------------------
    // Persistence
    // ---------------------------------------------------------------------
    MerchantRepository::class => autowire(SqliteMerchantRepository::class),
    ApiCredentialRepository::class => autowire(SqliteApiCredentialRepository::class),
    PaymentRepository::class => autowire(SqlitePaymentRepository::class),
    WebhookRepository::class => autowire(SqliteWebhookRepository::class),
    AdminUserRepository::class => autowire(SqliteAdminUserRepository::class),
    Settings::class => autowire(SqliteSettings::class),

    // The raw repository. Injected by name into the admin panel, which must
    // see an operator's real stored configuration.
    SqliteGatewayRepository::class => autowire(),

    // The one the payment flow uses, wrapped so the global sandbox switch can
    // override every gateway at once.
    GatewayRepository::class => autowire(SandboxEnforcingGatewayRepository::class)
        ->constructorParameter('inner', get(SqliteGatewayRepository::class)),

    ReportingRepository::class => static function (ContainerInterface $c): ReportingRepository {
        $settings = $c->get(Settings::class);

        return new SqliteReportingRepository(
            $c->get(PDO::class),
            $settings->get(SettingKey::TIMEZONE, 'UTC') ?? 'UTC',
        );
    },

    // ---------------------------------------------------------------------
    // Gateway drivers — this is the list you extend to add a PSP.
    // ---------------------------------------------------------------------
    DriverRegistry::class => static function (ContainerInterface $c): DriverRegistry {
        /** @var list<class-string> $drivers */
        $drivers = require __DIR__ . '/drivers.php';

        return new ContainerDriverRegistry(
            array_map(static fn (string $class): object => $c->get($class), $drivers),
        );
    },

    // ---------------------------------------------------------------------
    // Application services
    // ---------------------------------------------------------------------
    GatewayRouter::class => autowire(),
    WebhookPayloadFactory::class => autowire(),
    WebhookPublisher::class => autowire(),
    WebhookSender::class => autowire(HttpWebhookSender::class),

    RetrySchedule::class => static function (ContainerInterface $c): RetrySchedule {
        /** @var array{webhook: array{retry_schedule: string}} $settings */
        $settings = $c->get('settings');

        return RetrySchedule::fromString($settings['webhook']['retry_schedule']);
    },

    CreatePaymentHandler::class => static function (ContainerInterface $c): CreatePaymentHandler {
        /** @var array{payment: array{ttl_minutes: int}} $settings */
        $settings = $c->get('settings');

        return new CreatePaymentHandler(
            $c->get(PaymentRepository::class),
            $c->get(MerchantRepository::class),
            $c->get(Clock::class),
            $c->get(UrlBuilder::class),
            $settings['payment']['ttl_minutes'],
        );
    },

    StartCheckoutHandler::class => static function (ContainerInterface $c): StartCheckoutHandler {
        /** @var array{payment: array{max_failover: int}} $settings */
        $settings = $c->get('settings');

        return new StartCheckoutHandler(
            $c->get(PaymentRepository::class),
            $c->get(GatewayRouter::class),
            $c->get(DriverRegistry::class),
            $c->get(UrlBuilder::class),
            $c->get(Clock::class),
            $c->get(LoggerInterface::class),
            $settings['payment']['max_failover'],
        );
    },

    SettlePaymentHandler::class => autowire(),
    ReconcilePaymentsHandler::class => autowire(),
    ExpirePaymentsHandler::class => autowire(),
    ProcessWebhookQueueHandler::class => autowire(),

    // ---------------------------------------------------------------------
    // HTTP middleware
    // ---------------------------------------------------------------------
    ApiErrorMiddleware::class => static function (ContainerInterface $c): ApiErrorMiddleware {
        /** @var array{app: array{debug: bool}} $settings */
        $settings = $c->get('settings');

        return new ApiErrorMiddleware($c->get(LoggerInterface::class), $settings['app']['debug']);
    },

    ApiAuthenticationMiddleware::class => static function (
        ContainerInterface $c,
    ): ApiAuthenticationMiddleware {
        /** @var array{api: array{signature_tolerance: int, rate_limit: int}} $settings */
        $settings = $c->get('settings');

        return new ApiAuthenticationMiddleware(
            $c->get(ApiCredentialRepository::class),
            $c->get(MerchantRepository::class),
            $c->get(RequestSigner::class),
            $c->get(NonceStore::class),
            $c->get(RateLimiter::class),
            $c->get(Clock::class),
            $settings['api']['signature_tolerance'],
            $settings['api']['rate_limit'],
        );
    },

    // ---------------------------------------------------------------------
    // Presentation
    // ---------------------------------------------------------------------
    Translator::class => static function (ContainerInterface $c): Translator {
        /** @var array{paths: array{translations: string}} $settings */
        $settings = $c->get('settings');

        return new Translator($settings['paths']['translations']);
    },

    DateFormatter::class => autowire(),
    PanelContext::class => autowire(),

    Environment::class => static function (ContainerInterface $c): Environment {
        /** @var array{app: array{debug: bool}, paths: array{templates: string, cache: string}} $settings */
        $settings = $c->get('settings');

        $twig = new Environment(
            new FilesystemLoader($settings['paths']['templates']),
            [
                'debug' => $settings['app']['debug'],
                'cache' => $settings['app']['debug']
                    ? false
                    : $settings['paths']['cache'] . '/twig',
                'strict_variables' => $settings['app']['debug'],
            ],
        );

        $twig->addExtension($c->get(AppTwigExtension::class));

        return $twig;
    },
];
