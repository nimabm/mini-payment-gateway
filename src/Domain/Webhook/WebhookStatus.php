<?php

declare(strict_types=1);

namespace App\Domain\Webhook;

enum WebhookStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';

    /** Every retry was used up. The merchant must reconcile by polling. */
    case Exhausted = 'exhausted';
}
