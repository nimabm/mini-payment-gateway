<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Domain\Admin\AdminUserId;
use App\Domain\Admin\AdminUserRepository;
use App\Presentation\Support\PanelContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Guards every admin route, and resolves the panel's language and calendar for
 * the signed-in user before anything renders.
 *
 * Also enforces CSRF on state-changing requests: an admin panel that can create
 * API keys is exactly the kind of target a cross-site request would aim at.
 */
final readonly class AdminAuthMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE_USER = 'admin_user';

    public function __construct(
        private Session $session,
        private AdminUserRepository $users,
        private PanelContext $context,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $this->session->start();

        $userId = $this->session->get('user_id');
        $user = is_string($userId)
            ? $this->users->find(AdminUserId::fromString($userId))
            : null;

        if ($user === null) {
            return (new Response(302))->withHeader('Location', '/admin/login');
        }

        $this->context->resolve($user, $this->session->all());

        // A failed CSRF check is not "try again" — it means the request did not
        // originate from a page we rendered, so it is refused outright rather
        // than redirected somewhere the browser would replay it.
        if ($this->isStateChanging($request) && !$this->session->verifyCsrf($this->tokenFrom($request))) {
            $response = new Response(403);
            $response->getBody()->write('Invalid or missing CSRF token.');

            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE_USER, $user));
    }

    private function isStateChanging(ServerRequestInterface $request): bool
    {
        return in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private function tokenFrom(ServerRequestInterface $request): ?string
    {
        $body = $request->getParsedBody();

        if (is_array($body) && isset($body['_token']) && is_string($body['_token'])) {
            return $body['_token'];
        }

        $header = $request->getHeaderLine('X-CSRF-Token');

        return $header === '' ? null : $header;
    }
}
