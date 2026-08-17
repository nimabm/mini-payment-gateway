<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Domain\Gateway\GatewayId;
use App\Domain\Gateway\GatewayRepository;
use App\Domain\Merchant\ApiCredential;
use App\Domain\Merchant\ApiCredentialId;
use App\Domain\Merchant\ApiCredentialRepository;
use App\Domain\Merchant\Merchant;
use App\Domain\Merchant\MerchantId;
use App\Domain\Merchant\MerchantRepository;
use App\Domain\Shared\Clock;
use App\Domain\Shared\Currency;
use App\Infrastructure\Audit\AuditLogger;
use App\Infrastructure\Security\ApiKeyFactory;
use App\Presentation\Support\TemplateRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Managing the websites that connect to this gateway, and their API keys.
 */
final readonly class MerchantController
{
    use ResolvesActor;

    public function __construct(
        private MerchantRepository $merchants,
        private ApiCredentialRepository $credentials,
        private GatewayRepository $gateways,
        private ApiKeyFactory $keys,
        private TemplateRenderer $renderer,
        private Session $session,
        private AuditLogger $audit,
        private Clock $clock,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderer->render(new Response(), 'admin/merchants/index.html.twig', [
            'merchants' => $this->merchants->all(),
            'csrfToken' => $this->session->csrfToken(),
        ]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderer->render(new Response(), 'admin/merchants/form.html.twig', [
            'merchant' => null,
            'currencies' => Currency::cases(),
            'gateways' => $this->gateways->all(),
            'assigned' => [],
            'csrfToken' => $this->session->csrfToken(),
        ]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->input($request);

        $slug = $this->slugify($input->string('slug') ?: $input->string('name'));

        if ($slug === '' || $this->merchants->slugExists($slug)) {
            $this->session->flash('merchants.slug_hint', 'danger');

            return $this->redirect('/admin/merchants/new');
        }

        $merchant = Merchant::register(
            name: $input->string('name'),
            slug: $slug,
            defaultCurrency: Currency::from($input->string('default_currency', Currency::IRT->value)),
            now: $this->clock->now(),
            webhookUrl: $input->nullableString('webhook_url'),
            allowedCallbackHosts: $this->lines($input->string('allowed_callback_hosts')),
            ipAllowlist: $this->lines($input->string('ip_allowlist')),
        );

        $this->merchants->save($merchant);
        $this->gateways->assignToMerchant($merchant->id, $this->gatewayIds($request));

        $this->audit->record(
            $this->actor($request),
            'merchant.created',
            $merchant->id->value,
            ['name' => $merchant->name(), 'slug' => $slug],
            $this->ip($request),
        );

        return $this->redirect('/admin/merchants/' . $merchant->id->value);
    }

    /**
     * @param array<string, string> $arguments
     */
    public function show(ServerRequestInterface $request, ResponseInterface $slimResponse, array $arguments): ResponseInterface
    {
        $merchant = $this->merchants->find(MerchantId::fromString($arguments['id']));

        if ($merchant === null) {
            return $this->redirect('/admin/merchants');
        }

        return $this->renderer->render(new Response(), 'admin/merchants/show.html.twig', [
            'merchant' => $merchant,
            'credentials' => $this->credentials->findForMerchant($merchant->id),
            'gateways' => $this->gateways->findAssignedTo($merchant->id),
            // Shown exactly once, right after it is minted.
            'freshSecret' => $this->session->pull('fresh_secret'),
            'freshKeyId' => $this->session->pull('fresh_key_id'),
            'csrfToken' => $this->session->csrfToken(),
        ]);
    }

    /**
     * @param array<string, string> $arguments
     */
    public function edit(ServerRequestInterface $request, ResponseInterface $slimResponse, array $arguments): ResponseInterface
    {
        $merchant = $this->merchants->find(MerchantId::fromString($arguments['id']));

        if ($merchant === null) {
            return $this->redirect('/admin/merchants');
        }

        return $this->renderer->render(new Response(), 'admin/merchants/form.html.twig', [
            'merchant' => $merchant,
            'currencies' => Currency::cases(),
            'gateways' => $this->gateways->all(),
            'assigned' => array_map(
                static fn (GatewayId $id): string => $id->value,
                $this->gateways->assignedIds($merchant->id),
            ),
            'csrfToken' => $this->session->csrfToken(),
        ]);
    }

    /**
     * @param array<string, string> $arguments
     */
    public function update(ServerRequestInterface $request, ResponseInterface $slimResponse, array $arguments): ResponseInterface
    {
        $merchant = $this->merchants->find(MerchantId::fromString($arguments['id']));

        if ($merchant === null) {
            return $this->redirect('/admin/merchants');
        }

        $input = $this->input($request);

        $merchant->update(
            name: $input->string('name'),
            defaultCurrency: Currency::from($input->string('default_currency', Currency::IRT->value)),
            webhookUrl: $input->nullableString('webhook_url'),
            allowedCallbackHosts: $this->lines($input->string('allowed_callback_hosts')),
            ipAllowlist: $this->lines($input->string('ip_allowlist')),
        );

        $this->merchants->save($merchant);
        $this->gateways->assignToMerchant($merchant->id, $this->gatewayIds($request));

        $this->audit->record(
            $this->actor($request),
            'merchant.updated',
            $merchant->id->value,
            [],
            $this->ip($request),
        );

        return $this->redirect('/admin/merchants/' . $merchant->id->value);
    }

    /**
     * @param array<string, string> $arguments
     */
    public function toggleStatus(ServerRequestInterface $request, ResponseInterface $slimResponse, array $arguments): ResponseInterface
    {
        $merchant = $this->merchants->find(MerchantId::fromString($arguments['id']));

        if ($merchant === null) {
            return $this->redirect('/admin/merchants');
        }

        $merchant->status()->canCreatePayments()
            ? $merchant->suspend()
            : $merchant->activate();

        $this->merchants->save($merchant);

        $this->audit->record(
            $this->actor($request),
            'merchant.status_changed',
            $merchant->id->value,
            ['status' => $merchant->status()->value],
            $this->ip($request),
        );

        return $this->redirect('/admin/merchants/' . $merchant->id->value);
    }

    /**
     * Mints a new key pair. The secret is flashed to the session so it can be
     * shown once on the next page and then never again.
     *
     * @param array<string, string> $arguments
     */
    public function issueCredential(ServerRequestInterface $request, ResponseInterface $slimResponse, array $arguments): ResponseInterface
    {
        $merchant = $this->merchants->find(MerchantId::fromString($arguments['id']));

        if ($merchant === null) {
            return $this->redirect('/admin/merchants');
        }

        $pair = $this->keys->create();
        $input = $this->input($request);

        $credential = ApiCredential::issue(
            $merchant->id,
            $pair['keyId'],
            $pair['secret'],
            $input->string('label', 'default'),
            $this->clock->now(),
        );

        $this->credentials->save($credential);

        $this->session->set('fresh_secret', $pair['secret']);
        $this->session->set('fresh_key_id', $pair['keyId']);

        $this->audit->record(
            $this->actor($request),
            'credential.issued',
            $credential->id->value,
            ['merchant' => $merchant->slug(), 'label' => $credential->label()],
            $this->ip($request),
        );

        return $this->redirect('/admin/merchants/' . $merchant->id->value);
    }

    /**
     * @param array<string, string> $arguments
     */
    public function revokeCredential(ServerRequestInterface $request, ResponseInterface $slimResponse, array $arguments): ResponseInterface
    {
        $credential = $this->credentials->find(ApiCredentialId::fromString($arguments['credentialId']));

        if ($credential === null) {
            return $this->redirect('/admin/merchants');
        }

        $credential->revoke($this->clock->now());
        $this->credentials->save($credential);

        $this->audit->record(
            $this->actor($request),
            'credential.revoked',
            $credential->id->value,
            [],
            $this->ip($request),
        );

        return $this->redirect('/admin/merchants/' . $credential->merchantId->value);
    }

    private function input(ServerRequestInterface $request): FormInput
    {
        return FormInput::fromRequest($request);
    }

    /**
     * @return list<GatewayId>
     */
    private function gatewayIds(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        $raw = is_array($body) ? ($body['gateways'] ?? []) : [];

        if (!is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $value) {
            if (is_string($value) && $value !== '') {
                $ids[] = GatewayId::fromString($value);
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function lines(string $value): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];

        return array_values(array_filter(
            array_map('trim', $lines),
            static fn (string $line): bool => $line !== '',
        ));
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

}
