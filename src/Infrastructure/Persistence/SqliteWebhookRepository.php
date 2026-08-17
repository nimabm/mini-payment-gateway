<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Merchant\MerchantId;
use App\Domain\Payment\PaymentId;
use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookDeliveryId;
use App\Domain\Webhook\WebhookRepository;
use App\Domain\Webhook\WebhookStatus;
use DateTimeImmutable;
use PDO;

final readonly class SqliteWebhookRepository implements WebhookRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function save(WebhookDelivery $delivery): void
    {
        Rows::execute(
            $this->pdo,
            'INSERT INTO webhook_deliveries (
                id, merchant_id, payment_id, event, url, payload, status, attempts,
                next_attempt_at, last_response_code, last_error, created_at, delivered_at
             ) VALUES (
                :id, :merchant_id, :payment_id, :event, :url, :payload, :status, :attempts,
                :next_attempt_at, :last_response_code, :last_error, :created_at, :delivered_at
             )
             ON CONFLICT (id) DO UPDATE SET
                status = excluded.status,
                attempts = excluded.attempts,
                next_attempt_at = excluded.next_attempt_at,
                last_response_code = excluded.last_response_code,
                last_error = excluded.last_error,
                delivered_at = excluded.delivered_at',
            [
                'id' => $delivery->id->value,
                'merchant_id' => $delivery->merchantId->value,
                'payment_id' => $delivery->paymentId->value,
                'event' => $delivery->event,
                'url' => $delivery->url,
                'payload' => json_encode($delivery->payload, JSON_THROW_ON_ERROR),
                'status' => $delivery->status()->value,
                'attempts' => $delivery->attempts(),
                'next_attempt_at' => $delivery->nextAttemptAt()?->format('Y-m-d H:i:s'),
                'last_response_code' => $delivery->lastResponseCode(),
                'last_error' => $delivery->lastError(),
                'created_at' => $delivery->createdAt->format('Y-m-d H:i:s'),
                'delivered_at' => $delivery->deliveredAt()?->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function find(WebhookDeliveryId $id): ?WebhookDelivery
    {
        $row = Rows::one(
            $this->pdo,
            'SELECT * FROM webhook_deliveries WHERE id = :value',
            ['value' => $id->value],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function findDue(DateTimeImmutable $now, int $limit = 50): array
    {
        return array_map($this->hydrate(...), Rows::all(
            $this->pdo,
            'SELECT * FROM webhook_deliveries
             WHERE status = :status AND next_attempt_at IS NOT NULL AND next_attempt_at <= :now
             ORDER BY next_attempt_at
             LIMIT :limit',
            [
                'status' => WebhookStatus::Pending->value,
                'now' => $now->format('Y-m-d H:i:s'),
                'limit' => $limit,
            ],
        ));
    }

    public function findForPayment(PaymentId $paymentId): array
    {
        return array_map($this->hydrate(...), Rows::all(
            $this->pdo,
            'SELECT * FROM webhook_deliveries WHERE payment_id = :payment_id ORDER BY created_at',
            ['payment_id' => $paymentId->value],
        ));
    }

    private function hydrate(Row $row): WebhookDelivery
    {
        /** @var array<string, mixed> $payload */
        $payload = $row->json('payload');

        return new WebhookDelivery(
            WebhookDeliveryId::fromString($row->string('id')),
            MerchantId::fromString($row->string('merchant_id')),
            PaymentId::fromString($row->string('payment_id')),
            $row->string('event'),
            $row->string('url'),
            $payload,
            WebhookStatus::from($row->string('status')),
            $row->int('attempts'),
            $row->nullableDate('next_attempt_at'),
            $row->nullableInt('last_response_code'),
            $row->nullableString('last_error'),
            $row->date('created_at'),
            $row->nullableDate('delivered_at'),
        );
    }
}
