# Operations

## Before you take real money

- [ ] `APP_URL` is the public **HTTPS** URL of this service. Banks store it and
      send payers back to it; get it wrong and every payment strands.
- [ ] `APP_KEY` is set, backed up, and **never changed**. It decrypts the PSP
      credentials and the API secrets — losing it means re-entering every one of
      them.
- [ ] `APP_DEBUG=false` and `APP_ENV=production`.
- [ ] Every website has **Allowed return hosts** filled in. Empty means any
      host, which turns the gateway into an open redirect.
- [ ] TLS terminates in front of the `app` container — a reverse proxy,
      a load balancer, or Cloudflare. The container itself speaks plain HTTP.
- [ ] The `worker` container is running. Without it, stuck payments are never
      reconciled and failed webhooks are never retried.
- [ ] The admin panel is not reachable from the open internet, or is behind an
      IP allowlist at your reverse proxy.
- [ ] The demo website created by the first-run seed has been deleted or
      renamed, and its API key revoked.
- [ ] Backups of `/var/lib/gateway` are running and have been restored once.

---

## The stack

One image, two containers:

| Container | What it does |
|---|---|
| `app` | Apache with mod_php. Migrates and seeds on boot. |
| `worker` | Webhook retries, reconciliation, expiry, pruning. |

One volume, `gateway-data`, holding the SQLite file. Logs go to stdout and
stderr, so `docker compose logs` is all there is to it.

Upgrading is `make up` again: it rebuilds the image and the entrypoint applies
any new migrations before Apache starts.

---

## The worker

A loop: run every job, sleep 30 seconds, repeat. It handles `SIGTERM` and
`SIGINT` by finishing the current pass first, so `docker compose down` never
kills a webhook mid-delivery.

Jobs, in order:

| Job | What it prevents |
|---|---|
| Webhook queue | Merchants never learning a payment succeeded |
| Reconciliation | Payers charged for orders that were never fulfilled |
| Expiry | Abandoned carts polluting reports forever |
| Nonce pruning | The replay table growing without bound |
| Rate-limit pruning | Same |

Each is isolated — a PSP outage that breaks reconciliation cannot stop webhooks
from going out.

Without a long-running container, run the same work from cron:

```cron
* * * * * docker compose exec -T app php bin/console worker:once
```

---

## Backups

Everything lives in one SQLite file. Back it up **with SQLite**, not `cp` — the
WAL means a plain copy can be inconsistent.

```bash
docker compose exec -T app \
    sqlite3 /var/lib/gateway/gateway.sqlite ".backup '/var/lib/gateway/backup.sqlite'"

docker compose cp app:/var/lib/gateway/backup.sqlite ./backup-$(date +%F).sqlite
```

Back up `APP_KEY` separately, and not next to the database. The database without
the key is useless; that is the point.

### Restoring

```bash
docker compose down
docker compose cp ./backup-2024-08-16.sqlite app:/var/lib/gateway/gateway.sqlite
docker compose up -d
```

**Restoring a production backup into staging is the dangerous case** — it brings
live PSP credentials with it. Turn on **Settings → Force every gateway into
sandbox mode** before anything can charge a real card. That switch overrides
every gateway regardless of its own configuration.

---

## Monitoring

`GET /health` returns `{"status":"ok"}` — enough for a load balancer.

What is actually worth alerting on lives in the panel:

| Signal | Where | What it means |
|---|---|---|
| Payments awaiting verification, climbing | Dashboard | A PSP is unreachable, or the worker is down |
| Webhooks in *Gave up* | Webhooks | A merchant's endpoint has been failing for over a day |
| Success rate falling on one gateway | Reports → Gateway comparison | That bank is having a bad day — consider reordering priorities |
| Unfamiliar entries in the audit log | Audit log | Somebody changed something |

Logs are structured JSON on stderr, so `docker compose logs` pipes into
anything.

---

## Rotating an API key

Zero downtime, because a website may hold several active keys at once:

1. **Websites → the site → Issue new key.** Copy the secret.
2. Deploy the new key to that shop.
3. Watch **Last used** on the old key until it stops moving.
4. **Revoke** the old key.

Rotating `APP_KEY` is a different matter: there is no re-encryption command, so
it means re-entering every gateway credential and re-issuing every API key. Set
it once and keep it safe.

---

## Scaling

SQLite handles a few hundred thousand payments and a handful of concurrent
writers comfortably, especially in WAL mode where reads never block the write.

Signs you have outgrown it: `database is locked` in the logs despite the five
second busy timeout, or reports taking seconds. The fix is to reimplement the
`Sqlite*Repository` classes against PostgreSQL — the interfaces belong to the
domain and would not change, and nothing above the infrastructure layer would
notice.

Before that, the cheaper wins are:

- Raise `busy_timeout`.
- Narrow report periods; the default is 30 days.
- Run the worker less often if it is competing for the write lock.

---

## Troubleshooting

**A payment is stuck in "awaiting verification".** The payer may have been
charged. Open it in the panel and press **Ask the gateway**. If the bank is
unreachable, leave it — reconciliation retries on its own.

**`signature_expired` from a merchant.** That server's clock has drifted.
Fix NTP there; do not raise `API_SIGNATURE_TOLERANCE` to hide it.

**`no_gateway_available`.** No enabled gateway accepts that currency and amount
for that website. Check the assignment, the currency list, and the min/max
amounts.

**"Gateway credentials could not be decrypted."** `APP_KEY` has changed or is
missing. Restore the original key; the credentials cannot be recovered without
it.

**Nothing renders and the log says the template cache is unwritable.** Check
permissions on `var/cache`, or set `APP_DEBUG=true` temporarily to bypass the
cache.
