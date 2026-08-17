<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Domain\Admin\AdminUserRepository;
use App\Domain\Shared\Clock;
use App\Infrastructure\Audit\AuditLogger;
use App\Infrastructure\Security\RateLimiter;
use App\Presentation\Support\PanelContext;
use App\Presentation\Support\TemplateRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Sign in and out.
 *
 * Sits outside {@see AdminAuthMiddleware} for obvious reasons, so it resolves
 * the panel language itself — the login page must be readable before anyone
 * has a preference stored.
 */
final readonly class AuthController
{
    public function __construct(
        private AdminUserRepository $users,
        private Session $session,
        private TemplateRenderer $renderer,
        private PanelContext $context,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private Clock $clock,
    ) {
    }

    public function showLogin(ServerRequestInterface $request): ResponseInterface
    {
        $this->session->start();
        $this->context->resolve(null, $this->session->all());

        if (is_string($this->session->get('user_id'))) {
            return (new Response(302))->withHeader('Location', '/admin');
        }

        return $this->renderer->render(new Response(), 'admin/login.html.twig', [
            'csrfToken' => $this->session->csrfToken(),
            'error' => $this->session->pull('login_error'),
        ]);
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $this->session->start();
        $this->context->resolve(null, $this->session->all());

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        if (!$this->session->verifyCsrf(is_string($body['_token'] ?? null) ? $body['_token'] : null)) {
            return $this->failLogin('auth.failed');
        }

        $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        $ip = $this->clientIp($request);

        // Throttled per address, not per account, so an attacker cannot lock a
        // known administrator out by failing their login on purpose.
        if (!$this->rateLimiter->allow('login:' . ($ip ?? 'unknown'), 10)) {
            return $this->failLogin('auth.throttled');
        }

        $user = $this->users->findByEmail($email);

        if ($user === null || !$user->verifyPassword($password)) {
            $this->audit->record(null, 'auth.failed', $email, [], $ip);

            return $this->failLogin('auth.failed');
        }

        $user->recordLogin($this->clock->now());
        $this->users->save($user);

        $this->session->regenerate();
        $this->session->set('user_id', $user->id->value);
        $this->audit->record($user, 'auth.login', $user->email, [], $ip);

        return (new Response(302))->withHeader('Location', '/admin');
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $this->session->start();
        $this->session->destroy();

        return (new Response(302))->withHeader('Location', '/admin/login');
    }

    private function failLogin(string $messageKey): ResponseInterface
    {
        $this->session->set('login_error', $messageKey);

        return (new Response(302))->withHeader('Location', '/admin/login');
    }

    private function clientIp(ServerRequestInterface $request): ?string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($ip) ? $ip : null;
    }
}
