<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Presentation\Admin\AdminAuthMiddleware;
use App\Presentation\Admin\AuditController;
use App\Presentation\Admin\AuthController;
use App\Presentation\Admin\DashboardController;
use App\Presentation\Admin\GatewayController;
use App\Presentation\Admin\MerchantController;
use App\Presentation\Admin\ReportController;
use App\Presentation\Admin\SettingsController;
use App\Presentation\Admin\TransactionController;
use App\Presentation\Admin\WebhookController;
use App\Presentation\Api\ApiAuthenticationMiddleware;
use App\Presentation\Api\CreatePaymentAction;
use App\Presentation\Api\GetPaymentAction;
use App\Presentation\Api\ListGatewaysAction;
use App\Presentation\Api\VerifyPaymentAction;
use App\Presentation\Checkout\CheckoutAction;
use App\Presentation\Checkout\GatewayCallbackAction;
use App\Presentation\Checkout\SimulatorAction;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * All routes, grouped by audience: merchants (API), payers (checkout) and
 * operators (admin).
 *
 * Deliberately a class rather than a file that returns a closure. Slim rebinds
 * route and group callables to the container, and a static closure cannot be
 * bound — and closures inherit the static-ness of the scope they are declared
 * in, which a `require` from a static method silently imposes. Declaring them
 * inside an instance method removes that trap entirely.
 */
final class Routes
{
    /**
     * @param App<\Psr\Container\ContainerInterface|null> $app
     */
    public function __invoke(App $app): void
    {
        // -----------------------------------------------------------------------
        // Merchant API — signed requests only.
        // -----------------------------------------------------------------------
        $app->group('/api/v1', function (RouteCollectorProxy $group): void {
            $group->post('/payments', CreatePaymentAction::class);
            $group->get('/payments/{id}', GetPaymentAction::class);
            $group->post('/payments/{id}/verify', VerifyPaymentAction::class);
            $group->get('/gateways', ListGatewaysAction::class);
        })->add(ApiAuthenticationMiddleware::class);

        // -----------------------------------------------------------------------
        // Payer-facing. Unauthenticated by nature: a bank sends a browser here.
        // -----------------------------------------------------------------------
        $app->get('/pay/{id}', CheckoutAction::class);
        $app->map(
            ['GET', 'POST'],
            '/callback/{gatewayId}/{paymentId}',
            GatewayCallbackAction::class,
        );
        $app->get('/simulator/{id}', SimulatorAction::class);

        // -----------------------------------------------------------------------
        // Admin panel.
        // -----------------------------------------------------------------------
        $app->get('/admin/login', [AuthController::class, 'showLogin']);
        $app->post('/admin/login', [AuthController::class, 'login']);
        $app->get('/admin/logout', [AuthController::class, 'logout']);
        $app->get('/admin/locale', [SettingsController::class, 'switchLocale']);

        $app->group('/admin', function (RouteCollectorProxy $group): void {
            $group->get('', DashboardController::class);
            $group->get('/', DashboardController::class);

            $group->get('/transactions', [TransactionController::class, 'index']);
            $group->get('/transactions/export', [TransactionController::class, 'export']);
            $group->get('/transactions/{id}', [TransactionController::class, 'show']);
            $group->post('/transactions/{id}/verify', [TransactionController::class, 'verify']);

            $group->get('/merchants', [MerchantController::class, 'index']);
            $group->get('/merchants/new', [MerchantController::class, 'create']);
            $group->post('/merchants', [MerchantController::class, 'store']);
            $group->get('/merchants/{id}', [MerchantController::class, 'show']);
            $group->get('/merchants/{id}/edit', [MerchantController::class, 'edit']);
            $group->post('/merchants/{id}', [MerchantController::class, 'update']);
            $group->post('/merchants/{id}/status', [MerchantController::class, 'toggleStatus']);
            $group->post('/merchants/{id}/credentials', [MerchantController::class, 'issueCredential']);
            $group->post(
                '/merchants/{id}/credentials/{credentialId}/revoke',
                [MerchantController::class, 'revokeCredential'],
            );

            $group->get('/gateways', [GatewayController::class, 'index']);
            $group->get('/gateways/new', [GatewayController::class, 'create']);
            $group->post('/gateways', [GatewayController::class, 'store']);
            $group->get('/gateways/{id}/edit', [GatewayController::class, 'edit']);
            $group->post('/gateways/{id}', [GatewayController::class, 'update']);
            $group->post('/gateways/{id}/toggle', [GatewayController::class, 'toggle']);

            $group->get('/reports', ReportController::class);

            $group->get('/webhooks', [WebhookController::class, 'index']);
            $group->post('/webhooks/{id}/requeue', [WebhookController::class, 'requeue']);

            $group->get('/settings', [SettingsController::class, 'show']);
            $group->post('/settings', [SettingsController::class, 'update']);
            $group->post('/settings/preferences', [SettingsController::class, 'updatePreferences']);

            $group->get('/audit', AuditController::class);
        })->add(AdminAuthMiddleware::class);

        // -----------------------------------------------------------------------
        // Operational endpoints.
        // -----------------------------------------------------------------------
        $app->get('/health', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ): ResponseInterface {
            $response->getBody()->write(json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        });

        $app->get('/', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ): ResponseInterface {
            return $response->withStatus(302)->withHeader('Location', '/admin');
        });
    }
}
