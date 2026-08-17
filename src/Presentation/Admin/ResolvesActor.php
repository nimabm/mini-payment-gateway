<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Domain\Admin\AdminUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * The three lines every admin controller needs: who is acting, from where, and
 * how to send them somewhere else.
 */
trait ResolvesActor
{
    private function actor(ServerRequestInterface $request): ?AdminUser
    {
        $user = $request->getAttribute(AdminAuthMiddleware::ATTRIBUTE_USER);

        return $user instanceof AdminUser ? $user : null;
    }

    private function ip(ServerRequestInterface $request): ?string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($ip) ? $ip : null;
    }

    private function redirect(string $location): ResponseInterface
    {
        return (new Response(302))->withHeader('Location', $location);
    }
}
