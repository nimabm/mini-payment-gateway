# Adding a payment gateway

Adding a bank means writing **one class** and adding **one line** to a list.
Nothing else in the codebase changes — no migration, no controller, no template,
no change to any of your shops.

This document walks through it with a fictional PSP called *SamanPay*.

---

## 1. Write the driver

Create `src/Infrastructure/Gateway/SamanPay/SamanPayDriver.php`:

```php
final readonly class SamanPayDriver implements PaymentGatewayDriver
{
    public const NAME = 'samanpay';

    private const LIVE_API    = 'https://api.samanpay.example/v1/';
    private const SANDBOX_API = 'https://sandbox.samanpay.example/v1/';

    public function __construct(
        private ClientInterface $http,
        private LoggerInterface $logger,
    ) {
    }

    public function name(): DriverName
    {
        return DriverName::fromString(self::NAME);
    }

    public function displayName(): string
    {
        return 'SamanPay';
    }

    public function supports(Currency $currency): bool
    {
        return $currency->isIranian();
    }

    /** Renders this driver's settings form in the admin panel. */
    public function credentialFields(): array
    {
        return [
            new CredentialField('terminal_id', 'Terminal ID'),
            new CredentialField('api_key', 'API key'),
        ];
    }

    public function purchase(PurchaseRequest $request): PurchaseResponse { /* … */ }
    public function verify(VerificationRequest $request): VerificationResponse { /* … */ }
    public function inquire(VerificationRequest $request): VerificationResponse { /* … */ }

    public function supportsRefunds(): bool { return false; }
    public function refund(RefundRequest $request): RefundResponse
    {
        return RefundResponse::unsupported();
    }
}
```

`src/Infrastructure/Gateway/ZarinPal/ZarinPalDriver.php` is a complete working
example to copy from.

---

## 2. Register it

`config/drivers.php`:

```php
return [
    ZarinPalDriver::class,
    SamanPayDriver::class,   // ← the one line
    FakeDriver::class,
];
```

That is the whole wiring. The admin panel will now offer SamanPay when you add a
gateway, and render a form from `credentialFields()`.

---

## 3. Configure it in the panel

**Gateways → Add gateway → SamanPay.** Fill in the credentials, leave **Sandbox
mode** on, and enable it. Then assign it to a website under **Websites → …**.

---

## 4. Test it

Take a payment in sandbox. **Transactions → the payment** shows the attempt
timeline and the exact bytes exchanged, which is usually enough to debug a new
integration without adding a single `var_dump`.

---

## 5. Go live

Untick **Sandbox mode**. Your driver reads `$gateway->isSandbox()` to pick its
base URL, so that is the only change needed.

---

## The five rules

### 1. Do not throw for a business rejection

A declined amount or a wrong terminal id is a normal outcome. Return a failure
response and the router will fail over to the next gateway:

```php
return PurchaseResponse::failure('samanpay_' . $code, $message, $request, $response);
```

Reserve exceptions for genuine faults. Even those are caught — the checkout
handler records them as a failed attempt — but a rejection expressed as a
response is what lets the router make a decision.

### 2. Include `redirect_url` in the raw response

```php
return PurchaseResponse::success(
    reference:   $token,
    redirectUrl: $url,
    rawRequest:  $this->redact($payload),
    rawResponse: $response + ['redirect_url' => $url],
);
```

A payer who reloads the checkout page is sent back to the same bank session
using this value, instead of opening a second transaction at the bank.

### 3. Redact secrets from the stored payloads

Raw request and response are stored for auditing and shown in the panel. Strip
your terminal id and API key before returning them:

```php
private function redact(array $payload): array
{
    $payload['api_key'] = '***redacted***';

    return $payload;
}
```

### 4. Make `verify()` safe to call twice

Callbacks get redelivered, and reconciliation calls `inquire()` on anything it
finds stuck. If the PSP reports "already verified" as an error, translate it
into a success:

```php
if ($code === self::CODE_ALREADY_VERIFIED) {
    return VerificationResponse::alreadyVerified($refId, $cardPan, $fee, $response);
}
```

ZarinPal's code `101` is exactly this case.

### 5. Set timeouts, and never let `http_errors` throw

```php
$response = $this->http->request('POST', $url, [
    'json'            => $payload,
    'timeout'         => 20,
    'connect_timeout' => 5,
    'http_errors'     => false,   // many PSPs answer rejections with a 4xx body
]);
```

A driver without a timeout will eventually hold a payer's browser open until the
web server kills the request.

---

## `purchase()` vs `verify()` vs `inquire()`

| Method | Called when | Must it be safe to repeat? |
|---|---|---|
| `purchase()` | The payer arrives at the checkout page | No — a new call may open a new transaction |
| `verify()` | The payer returns from the bank | **Yes** |
| `inquire()` | The reconciliation worker, or the panel's manual button | **Yes**, and it should not settle anything as a side effect |

If your PSP has a read-only status endpoint, use it for `inquire()`. If it does
not — as with ZarinPal — `inquire()` can call the same verification endpoint,
provided repeating it is harmless.

---

## Testing your driver

Unit-test it against a mocked HTTP client; no network and no sandbox account
needed:

```php
$http = new Client(['handler' => HandlerStack::create(new MockHandler([
    new Response(200, [], json_encode(['data' => ['code' => 100, 'authority' => 'A-1']])),
]))]);

$driver = new SamanPayDriver($http, new NullLogger());

$response = $driver->purchase($request);

self::assertTrue($response->successful);
self::assertSame('A-1', $response->reference);
```

Then run the full flow — routing, failover, callback, webhooks — against the
real repositories the way `tests/Integration/PaymentFlowTest.php` does.
