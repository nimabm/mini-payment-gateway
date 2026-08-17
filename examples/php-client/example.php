<?php

declare(strict_types=1);

/**
 * A runnable end-to-end example.
 *
 *   php examples/php-client/example.php
 *
 * Set the three constants below from the panel (Websites → your site) or from
 * the output of `make init`.
 */

require __DIR__ . '/GatewayException.php';
require __DIR__ . '/GatewayClient.php';

use YourShop\Payments\GatewayClient;
use YourShop\Payments\GatewayException;

const GATEWAY_URL = 'http://localhost:8080';
const KEY_ID = 'pk_replace_me';
const SECRET = 'sk_replace_me';

$client = new GatewayClient(GATEWAY_URL, KEY_ID, SECRET);

try {
    $payment = $client->createPayment(
        amount: 150_000,               // 150,000 Toman
        orderId: 'DEMO-' . time(),
        callbackUrl: 'https://shop.example.com/payment/return',
        currency: 'IRT',
        extra: [
            'description' => 'A demo order',
            'payer_email' => 'customer@example.com',
        ],
    );

    echo "Payment created.\n";
    echo "  ID:     {$payment['id']}\n";
    echo "  Status: {$payment['status']}\n";
    echo "\nOpen this in a browser to pay:\n  {$payment['checkout_url']}\n\n";

    $fresh = $client->getPayment($payment['id']);

    echo "Current status: {$fresh['status']} (paid: " . ($fresh['paid'] ? 'yes' : 'no') . ")\n";
} catch (GatewayException $e) {
    fwrite(STDERR, sprintf(
        "The gateway rejected the request [%s / HTTP %d]: %s\n",
        $e->errorCode,
        $e->statusCode,
        $e->getMessage(),
    ));

    exit(1);
}
