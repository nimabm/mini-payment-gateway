# Mini Payment Gateway

A payment orchestration layer that sits between your websites and your payment
service providers.

Your sites integrate **once**, with this. Behind it you can add, remove, swap
and fail over between banks — ZarinPal today, three more next year, Stripe for
the foreign currency site — and not one line of code changes in any of your
shops.

```
   your sites                    this service                  the banks
┌───────────────┐            ┌──────────────────┐          ┌──────────────┐
│ shop-one.com  │───┐        │  REST API        │      ┌──▶│  ZarinPal    │
│ shop-two.com  │───┼───────▶│  routing +       │──────┤   ├──────────────┤
│ courses.ir    │───┘        │  failover        │      └──▶│  next bank…  │
└───────────────┘            │  admin panel     │          └──────────────┘
                             └──────────────────┘
```

---

## What it does

| | |
|---|---|
| **One API for every bank** | Your sites never learn what a ZarinPal Authority is. |
| **Automatic failover** | A bank that is down or rejects a request is recorded and the next one is tried. The payer sees nothing. |
| **Never double-charges** | Idempotency keys are enforced by a database constraint, not by hope. |
| **Never loses a payment** | Payments stuck between "charged" and "confirmed" are chased automatically until the PSP gives a definitive answer. |
| **Reliable notifications** | Signed server-to-server webhooks with a fixed retry schedule, so a closed browser tab cannot lose an order. |
| **Full reporting** | Per site, per gateway, per day, per hour — with date filters in the Jalali *or* Gregorian calendar and CSV export. |
| **Bilingual panel** | Persian (RTL) and English (LTR), switchable per installation, per user, or per session. |
| **Sandbox everywhere** | Per-gateway sandbox switch, plus a global "force sandbox" safety net for staging environments. |

---

## Quick start

Requires Docker. Nothing else — no PHP, no Composer on your machine.

```bash
make up
```

One command, and it is installed. It writes a `.env` with a fresh encryption
key, builds the image, starts two containers, migrates the database and seeds
an administrator — then prints the sign-in details:

```bash
make logs
```

Then open **/admin** on whatever host your reverse proxy serves. To run it
without a proxy, uncomment the `ports` block in `docker-compose.yml` and use
<http://localhost:8080/admin>.

Without `make`, it is two commands:

```bash
cp .env.example .env    # then put a key in APP_KEY: openssl rand -base64 32
docker compose up -d
```

The seed gives you a working setup you can click through immediately:

- an administrator
- a demo website with a live API key pair
- a **Simulator** gateway, enabled — so you can take a payment end to end
  without a bank account
- a **ZarinPal** connection, in sandbox and disabled, waiting for your merchant
  id

### Take your first payment

```bash
# Use the key id and secret that `make logs` showed on first run.
php examples/php-client/example.php
```

Or from the panel: **Websites → Demo Shop** shows the credentials, and
**Transactions** shows everything that happens.

---

## Going live with ZarinPal

1. **Gateways → ZarinPal → Edit**
2. Paste your merchant id from the ZarinPal panel into *Merchant ID*.
3. Leave **Sandbox mode** on and enable the gateway. Take a test payment.
4. When it works, turn **Sandbox mode** off. That single switch is the only
   difference between `sandbox.zarinpal.com` and `payment.zarinpal.com`.

Two things to set before real money moves:

- **Websites → your site → Allowed return hosts.** Empty means "any host",
  which turns your gateway into an open redirect. Fill it in.
- **`APP_URL`** in `.env` must be the public HTTPS URL of this service. The
  banks store it and send payers back to it. With `APP_ENV=production` the
  application refuses to start if this is still pointing at localhost.
- **Change the first-run password** from *Settings → Change your password*. It
  was printed into the container logs, which is no place for a live credential.

---

## Documentation

| | |
|---|---|
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | How the layers fit together and why |
| [API.md](docs/API.md) | Full REST reference, authentication, error codes |
| [INTEGRATION.md](docs/INTEGRATION.md) | Writing the module for your own site, step by step |
| [WEBHOOKS.md](docs/WEBHOOKS.md) | Payload shape, signature verification, retries |
| [ADDING_A_GATEWAY.md](docs/ADDING_A_GATEWAY.md) | Adding a new bank, in five steps |
| [OPERATIONS.md](docs/OPERATIONS.md) | Backups, the worker, going to production |
| [adr/](docs/adr/) | Why the big decisions were made, and what they cost |

---

## Commands

```bash
make up       # start (builds, migrates and seeds itself)
make down     # stop
make logs     # follow the logs
make sh       # shell inside the container
make test     # run the test suite
make check    # tests + static analysis + coding standards
make key      # print a fresh APP_KEY
make admin EMAIL=you@example.com   # create an administrator
make reset    # stop and delete the database — destroys every payment
```

Two containers, one image: `app` (Apache with mod_php) and `worker` (webhook
retries, reconciliation, expiry). Upgrading is `make up` again; migrations run
on boot.

Nothing is published to the host — `app` joins the external `nginx_proxy`
network so a reverse proxy can reach it at **`mini-payment-gateway-app:80`** and
terminate TLS. Running without a proxy? Uncomment the `ports` block in
`docker-compose.yml`. See [OPERATIONS.md](docs/OPERATIONS.md#networks).

---

## Project layout

```
src/
├── Domain/          the rules about money — no framework, no database, no HTTP
├── Application/     use cases, and the driver contract every bank plugs into
├── Infrastructure/  SQLite, PSP drivers, crypto, HTTP clients
└── Presentation/    REST API, checkout pages, admin panel
```

The dependency rule runs one way: `Presentation → Application → Domain`.
`Infrastructure` implements interfaces the inner layers declare and is wired in
at the composition root (`config/container.php`). Nothing in `Domain` knows that
SQLite, Slim or ZarinPal exist.

---

## Requirements

- Docker and Docker Compose
- Or, without Docker: PHP 8.3+ with `pdo_sqlite`, `sodium`, `json`; Composer

## Licence

MIT.

---

## Credits

Built by **Nima** and **Claude**.

| | |
|---|---|
| **Nima** | Software engineer and architect — direction, design decisions, review |
| **Claude** | Software developer — implementation |
