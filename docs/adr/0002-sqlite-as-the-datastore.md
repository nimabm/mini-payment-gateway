# 2. SQLite as the datastore

**Status:** accepted

## Context

The gateway needs durable, transactional storage for payments, and a reporting
workload that runs alongside live traffic.

## Decision

SQLite in WAL mode, with these settings applied on every connection:

```
PRAGMA foreign_keys = ON;    -- off by default, which silently voids every FK
PRAGMA busy_timeout = 5000;  -- wait for the write lock instead of failing
PRAGMA journal_mode = WAL;   -- readers no longer block the writer
PRAGMA synchronous = NORMAL;
```

And these conventions, enforced throughout:

- **Money is `INTEGER`** in the currency's minor unit. `REAL` never touches an
  amount.
- **Timestamps are UTC text**, `YYYY-MM-DD HH:MM:SS`, so they sort and compare
  in plain SQL. Tehran time and the Jalali calendar exist only at the
  presentation edge.
- **Reads are separate from writes.** `ReportingRepository` returns flat,
  pre-joined rows; it never hydrates an aggregate.

## Consequences

One file to back up, no database container, and a first run that needs no
credentials configured anywhere. WAL means reporting does not block payments.

The ceiling is concurrent writers. This design is comfortable into the hundreds
of thousands of payments; past that, the fix is to reimplement the seven
`Sqlite*Repository` classes against PostgreSQL. The interfaces they implement
belong to the domain, so nothing above the infrastructure layer would change —
which is the property that makes accepting this ceiling reasonable rather than
reckless.

Backups must go through `sqlite3 .backup`, not `cp`: with WAL enabled a plain
file copy can be inconsistent. This is documented in
[OPERATIONS.md](../OPERATIONS.md).
