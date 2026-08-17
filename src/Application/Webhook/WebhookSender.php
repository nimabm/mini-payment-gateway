<?php

declare(strict_types=1);

namespace App\Application\Webhook;

use App\Domain\Webhook\WebhookDelivery;

/**
 * Delivers one webhook over the wire. Implemented in the infrastructure layer
 * so the retry policy can be unit tested without a network.
 */
interface WebhookSender
{
    public function send(WebhookDelivery $delivery): WebhookSendResult;
}
