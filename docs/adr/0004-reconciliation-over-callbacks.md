# 4. Reconciliation as the source of truth, not callbacks

**Status:** accepted

## Context

The naive flow is: payer returns from the bank, we verify, we mark the order
paid. It works most of the time, and the times it does not are the ones that
matter — because they end with a customer who has been charged and an order
that was never fulfilled.

The ways it breaks are all ordinary:

- the payer closes the tab on the bank's page,
- the mobile connection drops during the redirect,
- the PSP is unreachable at the exact moment we try to verify,
- the callback arrives twice, or out of order,
- our own process dies mid-verification.

## Decision

Three mechanisms, all built around the assumption that the browser is not a
reliable transport.

**1. `AwaitingVerification` is a real state, written before the PSP is called.**
It means "the payer came back and we do not yet know what happened". A crash
during verification still leaves a record something can find. Expiry
deliberately skips this state, because the payer may already have paid.

**2. `SettlementOutcome::Undetermined` is a first-class outcome.** An
unreachable PSP is *not* a failure. Collapsing the two is precisely how a
gateway loses somebody's money. Undetermined payments stay open and get
retried.

**3. A worker chases them.** `ReconcilePaymentsHandler` picks up anything that
has sat in `AwaitingVerification` past a grace period and asks the PSP what
really happened, until there is a definitive answer.

Everything funnels through one idempotent `SettlePaymentHandler` — the payer's
return, the worker, and the manual button in the admin panel — so the rules
cannot drift between them.

## Consequences

A payment can be confirmed minutes after the payer has gone. The webhook queue
exists to tell the merchant when that happens, and `GET /payments/{id}` is
documented as the authoritative answer rather than the redirect.

Drivers must make `verify()` and `inquire()` safe to call repeatedly. For
ZarinPal that means translating error code 101, "already verified", into a
success — which is documented in
[ADDING_A_GATEWAY.md](../ADDING_A_GATEWAY.md) as a rule for every new driver.

The worker becomes a component you cannot skip. Without it stuck payments are
never resolved, so the operations checklist calls it out and the dashboard
surfaces the queue depth.
