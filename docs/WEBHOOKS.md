# Webhooks

## Why they exist

A payer's browser is not a reliable transport. Tabs get closed on the bank's
page, mobile connections drop during the redirect, and people press "back" at
exactly the wrong moment. If your shop only learns about a payment when the
customer's browser returns, you will lose orders — customers who paid, and were
never given what they bought.

Webhooks are the channel that does not depend on the payer being there.

---

## Configuring

**Websites → your site → Webhook URL.** It must be an absolute HTTPS URL.
Leave it empty to disable webhooks for that site.

---

## Events

| Event | When |
|---|---|
| `payment.succeeded` | A payment was confirmed by the bank |
| `payment.failed` | A payment ended as failed, canceled or unverifiable |

---

## The request

```
POST https://shop.example.com/payment/webhook
Content-Type: application/json
User-Agent: MiniPaymentGateway-Webhook/1.0
X-Gateway-Event: payment.succeeded
X-Gateway-Signature: 3f9a2c…
```

```json
{
  "event": "payment.succeeded",
  "sent_at": "2024-08-16T10:02:11+00:00",
  "data": {
    "payment_id": "0191c0f2-...-a1b2",
    "order_id": "ORDER-1024",
    "status": "paid",
    "amount": 150000,
    "currency": "IRT",
    "description": "Order #1024",
    "paid_at": "2024-08-16T10:02:11+00:00",
    "created_at": "2024-08-16T10:00:00+00:00",
    "transaction_id": "1234567890",
    "card_pan": "621986******1234",
    "failure_reason": null
  }
}
```

This shape is a published contract. Fields may be added over time; existing
fields keep their meaning.

---

## Verifying the signature

The body is signed with **your API secret** — the same secret you sign API
requests with. `X-Gateway-Signature` is `hash_hmac('sha256', $rawBody, $secret)`.

```php
$body      = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_GATEWAY_SIGNATURE'] ?? '';

if (!hash_equals(hash_hmac('sha256', $body, $secret), $signature)) {
    http_response_code(401);
    exit;
}
```

**Sign the raw body, not the decoded array.** Decoding and re-encoding changes
the bytes — key order, unicode escaping, whitespace — and the signature will
never match. Read `php://input` before any framework parses it.

If the merchant has several active keys, the **oldest active** one is used to
sign. During a key rotation, verify against every key you consider active.

---

## Responding

| Your response | What happens |
|---|---|
| `2xx` | Marked delivered. Done. |
| Anything else | Retried on the schedule below |
| No response / timeout (10s) | Retried |

Return `200` as soon as you have stored the event. Do not do slow work — send
the email, generate the invoice — before responding; a slow endpoint spends its
retries on timeouts.

---

## Retries

Attempts are made at, by default:

```
1 min → 5 min → 30 min → 2 h → 6 h → 24 h
```

Six attempts over roughly 32 hours, configured by `WEBHOOK_RETRY_SCHEDULE`.
After the last one, the delivery is marked **exhausted** and no more are sent.

Exhausted deliveries appear in **Webhooks** in the panel with a **Retry now**
button, and the payment itself is always readable through
`GET /api/v1/payments/{id}` — the API is the fallback when notifications fail.

---

## Rules for your endpoint

**Be idempotent.** The same event can arrive more than once: a delivery that
timed out after your server processed it will be retried. Check whether the
order is already paid before acting.

```php
if ($order->isPaid()) {
    http_response_code(200);
    exit;
}
```

**Do not follow the redirect chain.** Redirects are deliberately not followed,
so a `301` on your endpoint is treated as a failure rather than silently
forwarding the signature to another host. Point the URL at its final location.

**Treat the webhook as a hint, not proof, if you want belt and braces.** The
signature is sufficient, but calling `GET /payments/{id}` costs one request and
removes all doubt.

**Do not rely on ordering.** `payment.failed` for a retried payment and
`payment.succeeded` for the attempt that worked are separate deliveries with
separate retry schedules. Decide from `data.status`, not from arrival order.
