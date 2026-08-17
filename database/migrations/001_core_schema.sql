-- ---------------------------------------------------------------------------
-- Core schema.
--
-- Conventions used throughout:
--   * Identifiers are UUIDv7 strings.
--   * Money is an INTEGER in the currency's minor unit. Never REAL.
--   * Timestamps are TEXT in UTC, 'YYYY-MM-DD HH:MM:SS', so they sort
--     lexicographically and compare correctly in plain SQL.
-- ---------------------------------------------------------------------------

CREATE TABLE merchants (
    id                     TEXT PRIMARY KEY,
    name                   TEXT NOT NULL,
    slug                   TEXT NOT NULL UNIQUE,
    status                 TEXT NOT NULL DEFAULT 'active',
    default_currency       TEXT NOT NULL DEFAULT 'IRT',
    webhook_url            TEXT,
    allowed_callback_hosts TEXT NOT NULL DEFAULT '[]',
    ip_allowlist           TEXT NOT NULL DEFAULT '[]',
    created_at             TEXT NOT NULL
);

CREATE TABLE api_credentials (
    id           TEXT PRIMARY KEY,
    merchant_id  TEXT NOT NULL REFERENCES merchants (id) ON DELETE CASCADE,
    key_id       TEXT NOT NULL UNIQUE,
    -- Encrypted, not hashed: request signatures must be recomputed server side.
    secret_enc   TEXT NOT NULL,
    label        TEXT NOT NULL DEFAULT '',
    created_at   TEXT NOT NULL,
    last_used_at TEXT,
    revoked_at   TEXT
);

CREATE INDEX idx_api_credentials_merchant ON api_credentials (merchant_id);

CREATE TABLE gateways (
    id          TEXT PRIMARY KEY,
    driver      TEXT NOT NULL,
    label       TEXT NOT NULL,
    -- Encrypted blob; never readable from a database dump alone.
    credentials TEXT NOT NULL DEFAULT '',
    currencies  TEXT NOT NULL DEFAULT '[]',
    sandbox     INTEGER NOT NULL DEFAULT 1,
    enabled     INTEGER NOT NULL DEFAULT 0,
    priority    INTEGER NOT NULL DEFAULT 100,
    min_amount  INTEGER,
    max_amount  INTEGER,
    created_at  TEXT NOT NULL
);

-- Which merchant may use which gateway, and in what order they are tried.
CREATE TABLE merchant_gateways (
    merchant_id TEXT NOT NULL REFERENCES merchants (id) ON DELETE CASCADE,
    gateway_id  TEXT NOT NULL REFERENCES gateways (id) ON DELETE CASCADE,
    priority    INTEGER NOT NULL DEFAULT 100,
    PRIMARY KEY (merchant_id, gateway_id)
);

CREATE TABLE payments (
    id                   TEXT PRIMARY KEY,
    merchant_id          TEXT NOT NULL REFERENCES merchants (id) ON DELETE RESTRICT,
    order_id             TEXT NOT NULL,
    amount               INTEGER NOT NULL,
    currency             TEXT NOT NULL,
    refunded_amount      INTEGER NOT NULL DEFAULT 0,
    status               TEXT NOT NULL,
    description          TEXT,
    callback_url         TEXT NOT NULL,
    payer_name           TEXT,
    payer_email          TEXT,
    payer_mobile         TEXT,
    idempotency_key      TEXT,
    preferred_gateway_id TEXT REFERENCES gateways (id) ON DELETE SET NULL,
    failure_reason       TEXT,
    created_at           TEXT NOT NULL,
    updated_at           TEXT,
    expires_at           TEXT NOT NULL,
    paid_at              TEXT
);

-- One order can only ever have one payment per merchant.
CREATE UNIQUE INDEX idx_payments_order ON payments (merchant_id, order_id);

-- Enforces idempotency at the storage level, not just in application code.
CREATE UNIQUE INDEX idx_payments_idempotency
    ON payments (merchant_id, idempotency_key)
    WHERE idempotency_key IS NOT NULL;

CREATE INDEX idx_payments_status_created ON payments (status, created_at);
CREATE INDEX idx_payments_merchant_created ON payments (merchant_id, created_at);
CREATE INDEX idx_payments_created ON payments (created_at);
CREATE INDEX idx_payments_expiry ON payments (status, expires_at);

CREATE TABLE payment_attempts (
    id               TEXT PRIMARY KEY,
    payment_id       TEXT NOT NULL REFERENCES payments (id) ON DELETE CASCADE,
    gateway_id       TEXT NOT NULL REFERENCES gateways (id) ON DELETE RESTRICT,
    sequence         INTEGER NOT NULL,
    status           TEXT NOT NULL,
    reference        TEXT,
    transaction_id   TEXT,
    card_pan         TEXT,
    fee              INTEGER,
    failure_code     TEXT,
    failure_message  TEXT,
    request_payload  TEXT NOT NULL DEFAULT '{}',
    response_payload TEXT NOT NULL DEFAULT '{}',
    created_at       TEXT NOT NULL,
    completed_at     TEXT
);

CREATE UNIQUE INDEX idx_attempts_payment_sequence ON payment_attempts (payment_id, sequence);
CREATE INDEX idx_attempts_reference ON payment_attempts (reference);
CREATE INDEX idx_attempts_transaction ON payment_attempts (transaction_id);
CREATE INDEX idx_attempts_gateway ON payment_attempts (gateway_id, status);

CREATE TABLE webhook_deliveries (
    id                 TEXT PRIMARY KEY,
    merchant_id        TEXT NOT NULL REFERENCES merchants (id) ON DELETE CASCADE,
    payment_id         TEXT NOT NULL REFERENCES payments (id) ON DELETE CASCADE,
    event              TEXT NOT NULL,
    url                TEXT NOT NULL,
    payload            TEXT NOT NULL,
    status             TEXT NOT NULL DEFAULT 'pending',
    attempts           INTEGER NOT NULL DEFAULT 0,
    next_attempt_at    TEXT,
    last_response_code INTEGER,
    last_error         TEXT,
    created_at         TEXT NOT NULL,
    delivered_at       TEXT
);

CREATE INDEX idx_webhooks_due ON webhook_deliveries (status, next_attempt_at);
CREATE INDEX idx_webhooks_payment ON webhook_deliveries (payment_id);

CREATE TABLE admin_users (
    id            TEXT PRIMARY KEY,
    email         TEXT NOT NULL UNIQUE,
    name          TEXT NOT NULL DEFAULT '',
    password_hash TEXT NOT NULL,
    locale        TEXT,
    calendar      TEXT,
    created_at    TEXT NOT NULL,
    last_login_at TEXT
);

CREATE TABLE audit_logs (
    id          TEXT PRIMARY KEY,
    actor_id    TEXT,
    actor_email TEXT,
    action      TEXT NOT NULL,
    subject     TEXT,
    context     TEXT NOT NULL DEFAULT '{}',
    ip_address  TEXT,
    created_at  TEXT NOT NULL
);

CREATE INDEX idx_audit_created ON audit_logs (created_at);
CREATE INDEX idx_audit_action ON audit_logs (action, created_at);

CREATE TABLE settings (
    key        TEXT PRIMARY KEY,
    value      TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

-- Replay protection for signed API requests. Rows are pruned by the worker.
CREATE TABLE request_nonces (
    nonce      TEXT PRIMARY KEY,
    key_id     TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX idx_nonces_created ON request_nonces (created_at);

-- Fixed-window rate limiting. A window is one row; old rows are pruned.
CREATE TABLE rate_limits (
    bucket     TEXT NOT NULL,
    window_at  TEXT NOT NULL,
    hits       INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket, window_at)
);
