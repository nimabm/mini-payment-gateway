<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Gateway\DriverRegistry;
use App\Application\Gateway\PaymentGatewayDriver;
use App\Domain\Gateway\DriverName;
use App\Domain\Gateway\GatewayConfig;
use App\Domain\Gateway\GatewayId;
use App\Domain\Shared\Clock;
use App\Domain\Shared\Currency;
use App\Infrastructure\Audit\AuditLogger;
use App\Infrastructure\Persistence\SqliteGatewayRepository;
use App\Presentation\Support\PanelContext;
use App\Presentation\Support\TemplateRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Configuring PSP connections, including the per-gateway sandbox switch.
 *
 * Note the repository: this controller deliberately talks to the *undecorated*
 * SQLite repository rather than the sandbox-enforcing one. Reading through the
 * decorator would show every gateway as a sandbox while the global switch is
 * on, and saving that form back would silently overwrite the operator's real
 * setting.
 */
final readonly class GatewayController
{
    use ResolvesActor;

    public function __construct(
        private SqliteGatewayRepository $gateways,
        private DriverRegistry $drivers,
        private TemplateRenderer $renderer,
        private Session $session,
        private PanelContext $context,
        private AuditLogger $audit,
        private Clock $clock,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderer->render(new Response(), 'admin/gateways/index.html.twig', [
            'gateways' => $this->gateways->all(),
            'drivers' => $this->driverMap(),
            'sandboxForced' => $this->context->isSandboxForced(),
            'csrfToken' => $this->session->csrfToken(),
        ]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $driverName = $request->getQueryParams()['driver'] ?? null;
        $driver = is_string($driverName) && $this->drivers->has(DriverName::fromString($driverName))
            ? $this->drivers->get(DriverName::fromString($driverName))
            : ($this->drivers->all()[0] ?? null);

        if ($driver === null) {
            return $this->redirect('/admin/gateways');
        }

        return $this->renderer->render(new Response(), 'admin/gateways/form.html.twig', [
            'gateway' => null,
            'driver' => $driver,
            'drivers' => $this->drivers->all(),
            'fields' => $driver->credentialFields(),
            'currencies' => Currency::cases(),
            'sandboxForced' => $this->context->isSandboxForced(),
            'csrfToken' => $this->session->csrfToken(),
        ]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $input = FormInput::fromRequest($request);
        $driver = $this->drivers->get(DriverName::fromString($input->string('driver')));

        $gateway = GatewayConfig::configure(
            driver: $driver->name(),
            label: $input->string('label', $driver->displayName()),
            credentials: $this->credentialsFrom($input, $driver, []),
            currencies: $this->currenciesFrom($input),
            sandbox: $input->checkbox('sandbox'),
            now: $this->clock->now(),
            priority: $input->int('priority', 100),
        );

        if ($input->checkbox('enabled')) {
            $gateway->enable();
        }

        $this->gateways->save($gateway);

        $this->audit->record(
            $this->actor($request),
            'gateway.created',
            $gateway->id->value,
            ['driver' => $driver->name()->value, 'sandbox' => $gateway->isSandbox()],
            $this->ip($request),
        );

        return $this->redirect('/admin/gateways');
    }

    /**
     * @param array<string, string> $arguments
     */
    public function edit(ServerRequestInterface $request, ResponseInterface $slimResponse, array $arguments): ResponseInterface
    {
        $gateway = $this->gateways->find(GatewayId::fromString($arguments['id']));

        if ($gateway === null) {
            return $this->redirect('/admin/gateways');
        }

        $driver = $this->drivers->get($gateway->driver);

        return $this->renderer->render(new Response(), 'admin/gateways/form.html.twig', [
            'gateway' => $gateway,
            'driver' => $driver,
            'drivers' => $this->drivers->all(),
            'fields' => $driver->credentialFields(),
            'currencies' => Currency::cases(),
            'sandboxForced' => $this->context->isSandboxForced(),
            'csrfToken' => $this->session->csrfToken(),
        ]);
    }

    /**
     * @param array<string, string> $arguments
     */
    public function update(ServerRequestInterface $request, ResponseInterface $slimResponse, array $arguments): ResponseInterface
    {
        $gateway = $this->gateways->find(GatewayId::fromString($arguments['id']));

        if ($gateway === null) {
            return $this->redirect('/admin/gateways');
        }

        $input = FormInput::fromRequest($request);
        $driver = $this->drivers->get($gateway->driver);
        $wasSandbox = $gateway->isSandbox();

        $gateway->reconfigure(
            label: $input->string('label', $gateway->label()),
            credentials: $this->credentialsFrom($input, $driver, $gateway->credentials()),
            currencies: $this->currenciesFrom($input),
            sandbox: $input->checkbox('sandbox'),
            priority: $input->int('priority', $gateway->priority()),
            minAmount: $input->nullableInt('min_amount'),
            maxAmount: $input->nullableInt('max_amount'),
        );

        $input->checkbox('enabled') ? $gateway->enable() : $gateway->disable();

        $this->gateways->save($gateway);

        // Moving a gateway between sandbox and live is the single most
        // consequential switch in the panel, so it is called out by name in the
        // audit trail rather than buried in a generic "updated".
        $this->audit->record(
            $this->actor($request),
            $wasSandbox === $gateway->isSandbox() ? 'gateway.updated' : 'gateway.environment_changed',
            $gateway->id->value,
            [
                'label' => $gateway->label(),
                'sandbox' => $gateway->isSandbox(),
                'enabled' => $gateway->isEnabled(),
            ],
            $this->ip($request),
        );

        return $this->redirect('/admin/gateways');
    }

    /**
     * @param array<string, string> $arguments
     */
    public function toggle(ServerRequestInterface $request, ResponseInterface $slimResponse, array $arguments): ResponseInterface
    {
        $gateway = $this->gateways->find(GatewayId::fromString($arguments['id']));

        if ($gateway === null) {
            return $this->redirect('/admin/gateways');
        }

        $gateway->isEnabled() ? $gateway->disable() : $gateway->enable();
        $this->gateways->save($gateway);

        $this->audit->record(
            $this->actor($request),
            'gateway.toggled',
            $gateway->id->value,
            ['enabled' => $gateway->isEnabled()],
            $this->ip($request),
        );

        return $this->redirect('/admin/gateways');
    }

    /**
     * Empty credential fields keep their stored value, so an operator can edit
     * a label without re-typing a merchant id they may not have to hand.
     *
     * @param array<string, string> $existing
     * @return array<string, string>
     */
    private function credentialsFrom(
        FormInput $input,
        PaymentGatewayDriver $driver,
        array $existing,
    ): array {
        $credentials = $existing;

        foreach ($driver->credentialFields() as $field) {
            $value = $input->string('credentials_' . $field->key);

            if ($value !== '') {
                $credentials[$field->key] = $value;
            }
        }

        return $credentials;
    }

    /**
     * @return list<Currency>
     */
    private function currenciesFrom(FormInput $input): array
    {
        $currencies = [];

        foreach ($input->stringList('currencies') as $code) {
            $currency = Currency::tryFrom($code);

            if ($currency !== null) {
                $currencies[] = $currency;
            }
        }

        return $currencies;
    }

    /**
     * @return array<string, PaymentGatewayDriver>
     */
    private function driverMap(): array
    {
        $map = [];

        foreach ($this->drivers->all() as $driver) {
            $map[$driver->name()->value] = $driver;
        }

        return $map;
    }
}
