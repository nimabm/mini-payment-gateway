# Architecture

## The problem this shape solves

A payment aggregator has one job that matters: **your shops must never learn
anything about the bank behind the transaction.** The moment a shop knows what
an "Authority" is, or that ZarinPal returns `code: 101` for an
already-verified transaction, you have lost the ability to change banks without
touching every shop you own.

Everything below follows from protecting that boundary.

---

## Layers

```
┌──────────────────────────────────────────────────────────────┐
│ Presentation      REST API · checkout pages · admin panel    │
│                   Slim, Twig, PSR-7                          │
├──────────────────────────────────────────────────────────────┤
│ Application       use cases, the gateway driver contract,    │
│                   routing and failover, reporting queries    │
├──────────────────────────────────────────────────────────────┤
│ Domain            Payment, Money, the state machine,         │
│                   Merchant, GatewayConfig — pure PHP         │
└──────────────────────────────────────────────────────────────┘
        ▲
        │ implements the interfaces the layers above declare
┌──────────────────────────────────────────────────────────────┐
│ Infrastructure    SQLite repositories · ZarinPal driver ·    │
│                   libsodium · Guzzle · Monolog               │
└──────────────────────────────────────────────────────────────┘
```

Dependencies point inward only. `Domain` has no `use` statement for anything
outside itself and PHP's standard library — you can read the whole business
model without knowing which database or framework is underneath.

`Infrastructure` sits *below* the diagram rather than beside it because it
depends on the inner layers, not the other way round. It supplies
implementations for interfaces the inner layers own
(`PaymentRepository`, `PaymentGatewayDriver`, `Clock`), and everything is
assembled in one place: `config/container.php`.

---

## The seam that makes banks pluggable

```php
interface PaymentGatewayDriver
{
    public function purchase(PurchaseRequest $r): PurchaseResponse;
    public function verify(VerificationRequest $r): VerificationResponse;
    public function inquire(VerificationRequest $r): VerificationResponse;
    public function refund(RefundRequest $r): RefundResponse;
}
```

Four methods. Every PSP concept — Authority, RefID, token, `Status=NOK`, the
Rial/Toman switch — lives behind this interface, inside one driver class.

Two decisions here are deliberate and worth keeping:

**Drivers do not throw for business rejections.** A declined amount or a wrong
merchant id is a normal outcome that the router must be able to inspect and
route around, so it comes back as `PurchaseResponse::failure(...)`. Exceptions
are reserved for genuine faults, and even those are caught by the checkout
handler and recorded as a failed attempt rather than a failed sale.

**`DriverName` is a value object, not an enum.** An enum would mean the domain
had to be edited every time a bank was added, which is exactly the coupling this
design exists to prevent.

---

## The Payment aggregate

`Payment` is the aggregate root. Every rule about what may happen to money is
expressed there as a guarded transition, and nothing outside the class can
assign a status.

```
Created ──▶ Pending ──▶ AwaitingVerification ──▶ Paid ──▶ Refunded
   │           │                 │                 │      PartiallyRefunded
   ▼           ▼                 ▼                 │
Expired     Failed            Failed               │
            Canceled                               │
```

**`AwaitingVerification` is the state that matters.** The payer has been charged
but nothing has confirmed it. Money exists in limbo here. Three consequences run
through the whole codebase:

- The status is written to the database *before* the PSP is called, so a crash
  mid-verification still leaves a record reconciliation can find.
- Expiry deliberately skips this state — the payer may already have paid.
- The reconciliation worker exists solely to empty it.

Re-entering the same state is a no-op rather than an exception, because PSPs
redeliver callbacks routinely and a duplicate must not become a 500.

### Attempts, not fields

A payment has many `PaymentAttempt` records — one per trip to one bank. Failover
means overwriting fields on the payment would erase the story. Instead,
"ZarinPal timed out, the second gateway succeeded" is visible in the admin panel,
with the exact request and response bytes for each attempt.

---

## The payment flow

```
 shop                gateway                        bank
  │  POST /payments    │                             │
  │───────────────────▶│  (nothing sent to the bank) │
  │  {id, checkout_url}│                             │
  │◀───────────────────│                             │
  │                    │                             │
  │  payer → checkout_url                            │
  │                   ┌┴─ route: pick a gateway      │
  │                   └▶  purchase() ───────────────▶│
  │                    │◀── authority ───────────────│
  │                    │  redirect payer ───────────▶│
  │                    │                             │
  │                    │◀── payer returns ───────────│
  │                    │  verify() ─────────────────▶│
  │                    │◀── ref id ──────────────────│
  │  ◀─ signed redirect│                             │
  │  ◀─ webhook (retried)                            │
  │  GET /payments/{id} ── the authoritative answer  │
```

**Nothing is sent to a bank when the payment is created.** The PSP is only
contacted when a payer actually arrives at the checkout URL, which keeps
abandoned carts from filling your bank's dashboard with dead transactions.

The cost of that choice is paid at `/pay/{id}`: the payer waits on one
synchronous call to the PSP — around 1.6 s against ZarinPal's sandbox, against
about 8 ms of application overhead.

The handler then answers with a small self-redirecting document rather than a
`302`. ZarinPal matches the `Referer` of the request arriving at StartPay
against the domain registered for the gateway and warns the payer when it does
not match; a `302` sends no `Referer`, so only a navigation started by the
browser from a document on this domain satisfies it. The document carries its
markup, styles and script inline and starts the navigation from `<head>`, so
the extra cost is one request-free parse and the payer does not see it.

---

## Three guarantees, and where they live

### 1. No double charges — `CreatePaymentHandler` + a unique index

An idempotency key returns the existing payment rather than creating a second
one. It is enforced twice: in the handler, and by

```sql
CREATE UNIQUE INDEX idx_payments_idempotency
    ON payments (merchant_id, idempotency_key)
    WHERE idempotency_key IS NOT NULL;
```

Application-level checks race. The index does not.

### 2. No lost payments — `ReconcilePaymentsHandler`

Every few seconds the worker looks for payments that have sat in
`AwaitingVerification` past a grace period and asks the PSP what really
happened. This catches the payer whose browser died on the bank's page, the
callback lost to a network blip, and the verification that failed because the
PSP was down — all of which otherwise end with a charged customer and an
unpaid order.

`SettlementResult::Undetermined` exists for the same reason: an unreachable PSP
is *not* a failure, and collapsing it into one is how gateways lose people's
money.

### 3. No lost notifications — `WebhookDelivery` + `RetrySchedule`

Merchants must never depend on the payer's browser reaching their callback URL.
Deliveries are queued, signed with the merchant's own API secret, and retried on
an explicit schedule (`1, 5, 30, 120, 360, 1440` minutes) until the merchant
answers 2xx.

---

## Storage

SQLite, in WAL mode. For a service of this size that is a feature: one file to
back up, no separate database container, and readers that do not block the
writer.

Conventions that are enforced everywhere:

- **Money is `INTEGER`**, in the currency's minor unit. `REAL` never touches an
  amount.
- **Timestamps are UTC text**, `YYYY-MM-DD HH:MM:SS`, so they sort and compare
  correctly in plain SQL. Conversion to Tehran time and the Jalali calendar
  happens only at the presentation edge.
- **`PRAGMA foreign_keys = ON`**, because SQLite ignores them otherwise.
- **`busy_timeout = 5000`**, so contention waits instead of failing.

Where SQLite runs out: this design comfortably handles a few hundred thousand
payments and a handful of concurrent writers. Beyond that, replace the
`Sqlite*Repository` classes — the interfaces they implement are owned by the
domain and would not change.

### Reads are separate from writes

`ReportingRepository` returns wide, pre-joined, aggregated rows and never
hydrates an aggregate. Rendering ten thousand `Payment` objects to draw a table
would be slow and pointless, since nothing on that screen changes state.

---

## Security

| Concern | Where |
|---|---|
| Request authentication | HMAC-SHA256 over `METHOD\nPATH\nTIMESTAMP\nNONCE\nSHA256(body)` |
| Replay protection | Timestamp window **and** a single-use nonce — neither alone is enough |
| Open redirect | Merchant callback host allowlist, checked at creation |
| Credentials at rest | PSP credentials and API secrets encrypted with XChaCha20-Poly1305 under `APP_KEY` |
| Admin passwords | Argon2id, one-way |
| Panel CSRF | Per-session token on every state-changing request |
| Result tampering | The redirect back to the merchant is HMAC-signed |
| Accountability | Every mutating admin action writes an audit row, with secrets redacted |

Note the deliberate asymmetry: **admin passwords are hashed, API secrets are
encrypted.** An API secret is a *shared* secret — the server has to recompute
the request signature with it — so it cannot be hashed. It is stored encrypted
under a key that lives in the environment rather than the database.

---

## Language and calendar

`PanelContext` resolves the active language and calendar from three levels,
most specific first:

1. a per-session override (the header switcher),
2. the signed-in user's saved preference,
3. the installation default from Settings.

So one installation serves a Persian-speaking finance team and an
English-speaking developer at the same time.

`DateFormatter` handles both directions, and the second one is the one that
matters: **parsing**. When the calendar is set to Jalali, typing `1403/05/26`
into a report filter has to produce the correct UTC range — otherwise every
report silently covers the wrong period. That conversion lives in one class with
its own tests.

---

## Decisions worth knowing about

**Slim rather than Laravel.** The domain layer stays framework-free either way,
but Slim keeps the whole dependency tree small enough to read.

**A hand-written migration runner.** Migrations are plain `.sql` files applied
in order and recorded. Fifty lines, no dependency, and the schema stays readable
as SQL.

**Bar charts made of `div`s.** A charting library would be more dependency than
a daily trend justifies.

**The sandbox override is a repository decorator.**
`SandboxEnforcingGatewayRepository` applies the global switch on read, so the
rule is visible in the wiring and removable in one line. Writes pass through
untouched, and the admin panel deliberately reads the *undecorated* repository
so the form never shows — or saves back — an overridden value.
