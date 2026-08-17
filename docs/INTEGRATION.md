# Integrating a website

How to connect one of your shops to the gateway. Everything here uses the
ready-made client in [`examples/php-client/`](../examples/php-client/); copy
those two files into your project.

---

## 1. Register the website

In the panel: **Websites → Add website**.

| Field | What to put |
|---|---|
| Name | Anything recognisable |
| Identifier | Lowercase slug, e.g. `shop-one`. Cannot be changed later. |
| Default currency | `IRT` for Toman |
| Webhook URL | `https://shop.example.com/payment/webhook` — see [WEBHOOKS.md](WEBHOOKS.md) |
| Allowed return hosts | `shop.example.com`. **Do not leave this empty in production** — empty means any host, which turns the gateway into an open redirect. |
| Enabled gateways | Tick them in the order you want them tried |

Then **Issue new key**. The secret is shown **once**. Copy it now; it cannot be
recovered, only replaced.

---

## 2. Store the credentials

```php
// config/payments.php — never commit this
return [
    'gateway_url' => 'https://gateway.example.com',
    'key_id'      => 'pk_9f2c…',
    'secret'      => 'sk_4d81…',
];
```

Environment variables are better than a file. Either way, the secret must not
reach the browser: it signs requests server-side and nothing else.

---

## 3. Start a payment

```php
use YourShop\Payments\GatewayClient;
use YourShop\Payments\GatewayException;

$client = new GatewayClient($config['gateway_url'], $config['key_id'], $config['secret']);

try {
    $payment = $client->createPayment(
        amount:      $order->totalToman(),   // integer, no decimals for IRT
        orderId:     (string) $order->id,
        callbackUrl: 'https://shop.example.com/payment/return',
        currency:    'IRT',
        extra: [
            'description' => "Order #{$order->id}",
            'payer_email' => $order->customerEmail,
            'payer_mobile'=> $order->customerMobile,
        ],
    );
} catch (GatewayException $e) {
    // Show the customer a friendly failure; log $e->errorCode for yourself.
    return $this->paymentFailed($e);
}

$order->update([
    'payment_id'     => $payment['id'],
    'payment_status' => 'pending',
]);

header('Location: ' . $payment['checkout_url']);
exit;
```

### Amounts

`amount` is an **integer in the minor unit**.

| Currency | `150000` means |
|---|---|
| `IRT` | 150,000 Toman |
| `IRR` | 150,000 Rial |
| `USD` | $1,500.00 |

Toman and Rial have no decimal places, so there is no multiplication to do —
send the number as your shop displays it. Never send a float.

---

## 4. Handle the return

The customer comes back to your `callback_url` with signed parameters.

```php
// GET /payment/return
$parameters = $_GET;

// Step 1 — was this tampered with in the address bar?
if (!$client->verifyRedirect($parameters)) {
    return $this->show('Invalid payment response.');
}

// Step 2 — ask the API. This, not the redirect, is the truth.
$payment = $client->getPayment($parameters['payment_id']);

$order = Order::findByPaymentId($payment['id']);

if ($payment['paid'] && $payment['amount'] === $order->totalToman()) {
    $order->markPaid($payment['transaction_id']);

    return $this->show('Thank you — your payment was successful.');
}

$order->markFailed($payment['failure_reason']);

return $this->show('The payment was not completed.');
```

Three rules, in order of how often they are broken:

1. **Never trust `status` from the query string alone.** Verify the signature,
   then call the API.
2. **Check the amount.** Confirm the payment's amount matches the order you are
   about to fulfil.
3. **Make fulfilment idempotent.** A customer will press back and reload this
   page. `markPaid()` must be safe to call twice.

---

## 5. Handle the webhook

Browsers get closed on the bank's page every day. The webhook is what saves
those orders. See [WEBHOOKS.md](WEBHOOKS.md) for the full contract.

```php
// POST /payment/webhook
$body      = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_GATEWAY_SIGNATURE'] ?? '';

if (!$client->verifyWebhook($body, $signature)) {
    http_response_code(401);
    exit;
}

$event = json_decode($body, true);
$data  = $event['data'];

$order = Order::findByPaymentId($data['payment_id']);

if ($order && $event['event'] === 'payment.succeeded' && !$order->isPaid()) {
    $order->markPaid($data['transaction_id']);
}

http_response_code(200);   // anything else and we retry
```

---

## 6. Test before you go live

The **Simulator** gateway lets you run the whole flow with no bank:

1. **Gateways** → make sure *Simulator* is enabled.
2. **Websites → your site** → tick *Simulator*.
3. Run a checkout. You will land on a page with "pay" and "cancel" buttons.
4. Both paths — success and cancel — go through the same code as a real bank.

Check the result in **Transactions**: the full attempt timeline, and the exact
request and response bytes.

---

## Failure modes worth handling

| What happened | What you see | What to do |
|---|---|---|
| Customer closed the tab at the bank | No return, no webhook at first | The reconciliation worker settles it and the webhook arrives later. Do nothing. |
| The bank is unreachable during verification | `outcome: undetermined` | **Do not mark as failed.** Retry, or wait for the webhook. |
| Duplicate submit on your checkout button | `duplicate_order_id` | You already have a payment for this order — fetch it and redirect to its `checkout_url`. |
| `signature_expired` | 401 | Your server's clock is wrong. Fix NTP. |
| `no_gateway_available` | 503 | No enabled gateway can take that amount or currency. Check the panel. |

---

## A note on other platforms

The client is plain PHP with no framework dependencies, so it drops into
WordPress/WooCommerce, Laravel, Symfony or a hand-rolled shop unchanged. The
only platform-specific parts are where you store the credentials and where you
mark the order as paid.

For WooCommerce specifically, the mapping is:

| WooCommerce | This gateway |
|---|---|
| `process_payment()` | `createPayment()`, then redirect to `checkout_url` |
| Return URL handler | `verifyRedirect()` + `getPayment()` |
| A registered webhook endpoint | `verifyWebhook()` |
| `$order->payment_complete($txn)` | Called when `paid` is true |
