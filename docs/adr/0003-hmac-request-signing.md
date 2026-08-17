# 3. HMAC request signing instead of bearer tokens

**Status:** accepted

## Context

Merchant sites authenticate to the API. A bearer token is the obvious choice
and the one most PSPs use.

But a bearer token is a password in a header: anyone who sees a request can
replay it, and there is nothing binding the token to the request it arrived
with. Against a system that creates payment records, that is a weak position.

## Decision

Every request carries four headers and is signed:

```
METHOD \n PATH \n TIMESTAMP \n NONCE \n sha256(BODY)
```

HMAC-SHA256 with the merchant's secret, hex encoded.

- The secret is **never transmitted**, so it cannot be captured in transit or
  from a proxy log.
- The timestamp bounds replay to a five minute window.
- The nonce closes that window completely — each signature is single-use.
- Hashing the body rather than including it keeps the signed string short and
  binary-safe.

Both checks are needed. A timestamp alone allows replay inside the window; a
nonce alone means remembering every nonce ever seen.

The nonce is claimed **after** the signature verifies, so a forged request can
never burn a legitimate one.

## Consequences

This is more work for a merchant than `Authorization: Bearer`. The mitigation
is `examples/php-client/`, a dependency-free client that hides the whole thing
behind `createPayment()`. In practice a merchant copies two files and never
thinks about signing again.

The server must be able to recompute the signature, so the secret cannot be
hashed at rest — it is **encrypted** under `APP_KEY` instead. That is a real
difference from admin passwords, which are Argon2id and one-way, and it is
called out in the code so nobody "fixes" it later.

The nonce table needs pruning, which the worker does hourly; the timestamp
window bounds how much there is to keep.

Clock drift on a merchant's server becomes a visible failure
(`signature_expired`) rather than a silent one. That is a feature: the fix is
NTP, not a wider tolerance.
