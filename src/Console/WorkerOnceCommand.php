<?php

declare(strict_types=1);

namespace App\Console;

use App\Application\Payment\ExpirePaymentsHandler;
use App\Application\Payment\ReconcilePaymentsHandler;
use App\Application\Webhook\ProcessWebhookQueueHandler;
use App\Infrastructure\Security\NonceStore;
use App\Infrastructure\Security\RateLimiter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One pass of every background job.
 *
 * Split out from the loop so the same work can be driven by cron in an
 * environment where a long-running container is not an option, and so tests can
 * run a pass deterministically.
 */
final readonly class WorkerOnceCommand implements Command
{
    public function __construct(
        private ProcessWebhookQueueHandler $webhooks,
        private ReconcilePaymentsHandler $reconcile,
        private ExpirePaymentsHandler $expire,
        private NonceStore $nonces,
        private RateLimiter $rateLimiter,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(array $arguments): int
    {
        // Each job is isolated: a PSP outage that breaks reconciliation must
        // not stop webhooks from being delivered.
        $this->attempt('webhooks', fn (): int => $this->webhooks->handle());
        $this->attempt('reconciliation', fn (): int => $this->reconcile->handle());
        $this->attempt('expiry', fn (): int => $this->expire->handle());
        $this->attempt('nonce pruning', fn (): int => $this->nonces->prune(3600));
        $this->attempt('rate limit pruning', fn (): int => $this->rateLimiter->prune());

        return 0;
    }

    /**
     * @param callable(): int $job
     */
    private function attempt(string $name, callable $job): void
    {
        try {
            $processed = $job();

            if ($processed > 0) {
                $this->logger->info('Worker job completed.', ['job' => $name, 'processed' => $processed]);
            }
        } catch (Throwable $e) {
            $this->logger->error('Worker job failed.', ['job' => $name, 'exception' => $e->getMessage()]);
        }
    }
}
