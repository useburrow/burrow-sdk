# SQL Outbox Schema

Use this table for durable event delivery from plugin runtimes into Burrow.

## Migration SQL

```sql
CREATE TABLE burrow_outbox (
  id VARCHAR(64) PRIMARY KEY,
  event_key VARCHAR(191) NOT NULL,
  status VARCHAR(32) NOT NULL,
  attempt_count INTEGER NOT NULL DEFAULT 0,
  payload TEXT NOT NULL,
  last_error TEXT NULL,
  created_at TIMESTAMP NOT NULL,
  updated_at TIMESTAMP NOT NULL,
  next_attempt_at TIMESTAMP NULL,
  sent_at TIMESTAMP NULL
);

CREATE INDEX idx_burrow_outbox_status_next_attempt
  ON burrow_outbox (status, next_attempt_at);
```

If your plugin runtime treats `event_key` as globally unique per emitted event, you can add:

```sql
CREATE UNIQUE INDEX idx_burrow_outbox_event_key ON burrow_outbox (event_key);
```

## Column Notes

- `event_key`: Stable key that should remain unchanged across retries.
- `status`: One of `pending`, `retrying`, `sent`, `failed`.
- `attempt_count`: Incremented by the store when status changes after a send attempt.
- `next_attempt_at`: Retry scheduling hint used by `pullPending()`.
- `last_error`: Last failure message for observability and support.
- `sent_at`: Set when the row is successfully published.

## Pull Query Semantics

`pullPending(limit)` should select:

- all rows with `status = pending`
- rows with `status = retrying` where `next_attempt_at` is null or <= now

Order oldest-first (`created_at ASC`) to keep delivery behavior predictable.

## Engine Notes

- **SQLite**: store timestamps as UTC text (`YYYY-MM-DD HH:MM:SS`) for deterministic comparisons.
- **MySQL/Postgres**: prefer UTC timestamp columns and keep app/runtime timezone pinned to UTC.
- **Payload type**: `TEXT` works across engines; use `JSON` column type when your target database supports it and tooling expects native JSON operators.

## Concurrency Notes

`SqlOutboxStore` in this SDK is intentionally simple and framework-agnostic. For multi-worker setups, wrap outbox pulls in DB-specific claiming/locking to avoid duplicate delivery attempts:

- Postgres: `FOR UPDATE SKIP LOCKED` claim pattern
- MySQL 8+: similar row-claim updates in a transaction
- SQLite: single-writer process model recommended for worker loop
