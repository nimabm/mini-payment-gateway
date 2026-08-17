<?php

declare(strict_types=1);

namespace App\Domain\Webhook;

use App\Domain\Payment\PaymentId;
use DateTimeImmutable;

interface WebhookRepository
{
    public function save(WebhookDelivery $delivery): void;

    public function find(WebhookDeliveryId $id): ?WebhookDelivery;

    /**
     * Deliveries whose next attempt is due.
     *
     * @return list<WebhookDelivery>
     */
    public function findDue(DateTimeImmutable $now, int $limit = 50): array;

    /** @return list<WebhookDelivery> */
    public function findForPayment(PaymentId $paymentId): array;
}
