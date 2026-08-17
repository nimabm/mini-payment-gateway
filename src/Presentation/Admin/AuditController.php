<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Infrastructure\Audit\AuditLogger;
use App\Presentation\Support\TemplateRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Read-only view of the audit trail. There is deliberately no way to delete
 * from it through the panel.
 */
final readonly class AuditController
{
    public function __construct(
        private AuditLogger $audit,
        private TemplateRenderer $renderer,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderer->render(new Response(), 'admin/audit.html.twig', [
            'entries' => $this->audit->recent(200),
        ]);
    }
}
