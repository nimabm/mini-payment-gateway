# REST API reference

Base URL: `{APP_URL}/api/v1`

All requests and responses are JSON, UTF-8.

---

## Response shape

Every response has exactly one of two shapes.

**Success**

```json
{ "data": { "…": "…" } }
```

**Failure**

```json
{
  "error": {
    "code": "duplicate_order_id",
    "message": "A payment already exists for order \"ORDER-1\".",
    "details": { }
  }
}
```

So a client can branch on the presence of `error` and never special-case an
endpoint.

---

## Authentication

Every request is signed. There are four headers:

| Header | Value |
|---|---|
| `X-Gateway-Key` | Your key id, e.g. `pk_9f2c…` |
| `X-Gateway-Timestamp` | Current Unix timestamp, in seconds |
| `X-Gateway-Nonce` | A random string, unique per request |
| `X-Gateway-Signature` | Hex HMAC-SHA256, computed as below |

### Computing the signature

Build the canonical string:

```
METHOD \n PATH \n TIMESTAMP \n NONCE \n sha256(BODY)
```

- `METHOD` uppercase (`POST`)
- `PATH` the path only, no host and no query string (`/api/v1/payments`)
- `BODY` the exact bytes you send; for a GET, the empty string
- The separator is a literal newline

Then `hash_hmac('sha256', $canonical, $secret)`, hex encoded.

```php
$body      = json_encode($payload, JSON_UNESCAPED_UNICODE);
$timestamp = (string) time();
$nonce     = bin2hex(random_bytes(16));

$canonical = implode("\n", [
    'POST',
    '/api/v1/payments',
    $timestamp,
    $nonce,
    hash('sha256', $body),
]);

$signature = hash_hmac('sha256', $canonical, $secret);
```

A ready-made client is in [`examples/php-client/`](../examples/php-client/) —
copy it into your site rather than reimplementing this.

### Rules

- The timestamp must be within **±300 seconds** of the server's clock
  (`API_SIGNATURE_TOLERANCE`). If you get `signature_expired`, check NTP on your
  web server.
- A nonce may be used **once**. Reusing one returns `409 replayed_request`.
- Rate limit: **120 requests per minute** per key (`API_RATE_LIMIT`).
- If the website has an IP allowlist configured, the request must come from one
  of those addresses.

---

## `POST /payments`

Creates a payment and returns the URL to send the payer to.

Nothing is sent to any bank at this point — the PSP is contacted only when the
payer actually arrives at `checkout_url`.

### Request

```json
{
  "amount": 150000,
  "currency": "IRT",
  "order_id": "ORDER-1024",
  "callback_url": "https://shop.example.com/payment/return",
  "description": "Order #1024",
  "payer_email": "customer@example.com",
  "payer_mobile": "09120000000",
  "idempotency_key": "order-1024-attempt-1"
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `amount` | integer | yes | **Minor unit.** Toman and Rial have no decimals, so `150000` IRT is 150,000 Toman. USD is in cents. |
| `currency` | string | no | `IRT`, `IRR`, `USD`, `EUR`. Defaults to the website's currency. |
| `order_id` | string | yes | Your reference. Unique per website — a second payment for the same order is rejected. |
| `callback_url` | string | yes | Absolute URL. Must match the website's allowed return hosts. |
| `description` | string | no | Shown to the payer by some banks. |
| `payer_name` / `payer_email` / `payer_mobile` | string | no | Passed to the PSP where supported, and searchable in the panel. |
| `idempotency_key` | string | no | Also accepted as an `Idempotency-Key` header. Strongly recommended. |
| `gateway_id` | string | no | Force a specific gateway. Ignored if that gateway cannot take the payment. |

### Response — `201 Created`

```json
{
  "data": {
    "id": "0191c0f2-...-a1b2",
    "order_id": "ORDER-1024",
    "status": "created",
    "paid": false,
    "amount": 150000,
    "currency": "IRT",
    "checkout_url": "https://gateway.example.com/pay/0191c0f2-...-a1b2",
    "expires_at": "2024-08-16T10:30:00+00:00"
  }
}
```

Store `id`, then redirect the payer to `checkout_url`.

A replayed idempotency key returns the **same payment** with `200` instead of
`201`.

### Errors

| Status | Code | Meaning |
|---|---|---|
| 422 | `validation_failed` | See `details` for the offending fields |
| 422 | `callback_url_not_allowed` | The host is not on the website's allowlist |
| 409 | `duplicate_order_id` | That `order_id` already has a payment |
| 403 | `merchant_suspended` | The website is suspended in the panel |

---

## `GET /payments/{id}`

The authoritative answer to "was this order paid?".

**Treat this — not the browser redirect — as the source of truth.**

### Response — `200 OK`

```json
{
  "data": {
    "id": "0191c0f2-...-a1b2",
    "order_id": "ORDER-1024",
    "status": "paid",
    "paid": true,
    "amount": 150000,
    "currency": "IRT",
    "refunded_amount": 0,
    "transaction_id": "1234567890",
    "card_pan": "621986******1234",
    "failure_reason": null,
    "attempts": 1,
    "created_at": "2024-08-16T10:00:00+00:00",
    "expires_at": "2024-08-16T10:30:00+00:00",
    "paid_at": "2024-08-16T10:02:11+00:00"
  }
}
```

Check `paid`, and check that `amount` and `currency` match the order you are
about to fulfil.

Returns `404 payment_not_found` for an unknown id **and** for a payment
belonging to a different website — the API does not confirm that someone else's
id exists.

---

## `POST /payments/{id}/verify`

Forces a fresh check with the bank.

Normally unnecessary: the gateway verifies as soon as the payer returns, and
the reconciliation worker chases anything that got stuck. Use this when you want
an answer immediately after a lost callback rather than waiting for the worker.

Safe to call repeatedly. The response is the payment object plus:

```json
{ "data": { "…": "…", "outcome": "settled" } }
```

| `outcome` | Meaning |
|---|---|
| `settled` | Confirmed paid just now |
| `already_settled` | Was already paid |
| `failed` | The bank says it was not paid |
| `undetermined` | **The bank could not be reached.** Do not treat as failure — retry later. |

---

## `GET /gateways`

Lists the gateways this website may use, for building your own picker.
Credentials are never included.

```json
{
  "data": {
    "gateways": [
      {
        "id": "0191c0…",
        "label": "ZarinPal — main account",
        "provider": "ZarinPal",
        "sandbox": false,
        "currencies": ["IRT", "IRR"],
        "min_amount": 10000,
        "max_amount": null
      }
    ]
  }
}
```

---

## The return redirect

When the payer comes back from the bank, they are redirected to your
`callback_url` with signed query parameters:

```
https://shop.example.com/payment/return
    ?payment_id=0191c0f2-...
    &order_id=ORDER-1024
    &status=paid
    &amount=150000
    &currency=IRT
    &transaction_id=1234567890
    &signature=6f1c…
```

### Verifying it

Remove `signature`, sort the remaining parameters by key, build a query string,
and HMAC it with your secret:

```php
$parameters = $_GET;
$signature  = $parameters['signature'] ?? '';
unset($parameters['signature']);

ksort($parameters);

$expected = hash_hmac(
    'sha256',
    http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
    $secret,
);

if (!hash_equals($expected, $signature)) {
    // Tampered. Ignore it.
}
```

**Even with a valid signature, confirm with `GET /payments/{id}` before you
ship anything.** The redirect happens in the payer's browser; the API call does
not.

---

## Status values

| Status | `paid` | Meaning |
|---|---|---|
| `created` | false | Created; the payer has not reached a bank yet |
| `pending` | false | The payer is at the bank |
| `awaiting_verification` | false | The payer is back; being confirmed |
| `paid` | true | Confirmed |
| `failed` | false | The bank declined, or the payer canceled |
| `canceled` | false | Canceled |
| `expired` | false | The link expired unused |
| `refunded` | true | Fully refunded |
| `partially_refunded` | true | Partially refunded |

---

## Error codes

| Code | Status | |
|---|---|---|
| `unauthenticated` | 401 | Missing, unknown or revoked key |
| `signature_missing` | 401 | A required signature header is absent |
| `signature_invalid` | 401 | The signature does not match |
| `signature_expired` | 401 | The timestamp is outside the tolerance |
| `replayed_request` | 409 | That nonce has been used |
| `ip_not_allowed` | 403 | Source IP is not on the website's allowlist |
| `rate_limited` | 429 | Too many requests this minute |
| `validation_failed` | 422 | See `details` |
| `callback_url_not_allowed` | 422 | Return host not allowlisted |
| `duplicate_order_id` | 409 | Order already has a payment |
| `merchant_suspended` | 403 | Website suspended |
| `payment_not_found` | 404 | Unknown payment, or not yours |
| `payment_not_payable` | 409 | Already closed or expired |
| `no_gateway_available` | 503 | No enabled gateway can take it |
| `internal_error` | 500 | A bug on our side; check the logs |
