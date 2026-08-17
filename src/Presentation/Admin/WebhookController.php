<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Webhook\ProcessWebhookQueueHandler;
use App\Domain\Shared\Clock;
use App\Domain\Webhook\WebhookDeliveryId;
use App\Domain\Webhook\WebhookRepository;
use App\Infrastructure\Audit\AuditLogger;
use App\Presentation\Support\TemplateRenderer;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * The webhook queue, and the button to push a delivery back into it.
 *
 * The listing reads the table directly rather than through the repository:
 * this is a report over deliveries, not a use case that changes one, and the
 * write-side repository has no business growing a paginated query for it.
 */
final readonly class WebhookController
{
    use ResolvesActor;

    public function __construct(
        private PDO $pdo,
        private WebhookRepository $webhooks,
        private ProcessWebhookQueueHandler $processor,
        private TemplateRenderer $renderer,
        private Session $session,
        private AuditLogger $audit,
        private Clock $clock,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $status = $request->getQueryParams()['status'] ?? null;

        $sql = 'SELECT w.*, m.name AS merchant_name, p.order_id
                FROM webhook_deliveries w
                INNER JOIN merchants m ON m.id = w.merchant_id
                INNER JOIN payments p ON p.id = w.payment_id';

        $parameters = [];

        if (is_string($status) && $status !== '') {
            $sql .= ' WHERE w.status = :status';
            $parameters['status'] = $status;
        }

        $sql .= ' ORDER BY w.created_at DESC LIMIT 200';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $this->renderer->render(new Response(), 'admin/webhooks.html.twig', [
            'deliveries' => $statement->fetchAll(),
            'status' => $status,
            'csrfToken' => $this->session->csrfToken(),
        ]);
    }

    /**
     * @param array<string, string> $arguments
     */
    public function requeue(ServerRequestInterface $request, ResponseInterface $slimResponse, array $arguments): ResponseInterface
    {
        $delivery = $this->webhooks->find(WebhookDeliveryId::fromString($arguments['id']));

        if ($delivery === null) {
            return $this->redirect('/admin/webhooks');
        }

        $delivery->requeue($this->clock->now());
        $this->webhooks->save($delivery);

        // Sent immediately rather than left for the worker, because an operator
        // pressing "retry now" wants to see the result now.
        $this->processor->handle(1);

        $this->audit->record(
            $this->actor($request),
            'webhook.requeued',
            $delivery->id->value,
            ['payment_id' => $delivery->paymentId->value],
            $this->ip($request),
        );

        return $this->redirect('/admin/webhooks');
    }
}
