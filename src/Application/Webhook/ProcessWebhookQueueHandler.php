<?php

declare(strict_types=1);

namespace App\Application\Webhook;

use App\Domain\Shared\Clock;
use App\Domain\Webhook\WebhookRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drains the due webhook queue. Run by the worker container on a loop.
 */
final readonly class ProcessWebhookQueueHandler
{
    public function __construct(
        private WebhookRepository $deliveries,
        private WebhookSender $sender,
        private RetrySchedule $schedule,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int Number of deliveries processed.
     */
    public function handle(int $batchSize = 50): int
    {
        $due = $this->deliveries->findDue($this->clock->now(), $batchSize);

        foreach ($due as $delivery) {
            try {
                $result = $this->sender->send($delivery);
            } catch (Throwable $e) {
                $result = WebhookSendResult::rejected(null, $e->getMessage());
            }

            $now = $this->clock->now();

            if ($result->accepted) {
                $delivery->markDelivered((int) $result->statusCode, $now);
            } else {
                $delivery->markFailed(
                    $result->statusCode,
                    $result->error ?? 'Unknown error.',
                    $this->schedule->nextAttemptAfter($delivery->attempts() + 1, $now),
                );

                $this->logger->warning('Webhook delivery failed.', [
                    'delivery_id' => $delivery->id->value,
                    'payment_id' => $delivery->paymentId->value,
                    'attempts' => $delivery->attempts(),
                    'status_code' => $result->statusCode,
                ]);
            }

            $this->deliveries->save($delivery);
        }

        return count($due);
    }
}
