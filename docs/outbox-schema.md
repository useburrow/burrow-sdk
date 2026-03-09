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
