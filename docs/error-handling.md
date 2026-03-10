# Error Handling

The SDK separates transport/runtime failures from API-level status handling.

## Transport Errors

- `TransportFailureException`: Network-level request failures (DNS, connect timeout, connection reset, etc).
- `InvalidJsonException`: Invalid JSON in request encoding or response decoding.

`CurlHttpTransport` applies retry policy to transient failures:

- transport failures
- `5xx` HTTP responses

Retry behavior is configurable via `RetryPolicy`.

## Client Errors

- `UnexpectedResponseStatusException`: Burrow returned a status that is not accepted at runtime.

Accepted runtime statuses:

- `200`
- `207`

Any other status triggers `UnexpectedResponseStatusException`.
Use `isRetryable()` on this exception to distinguish `5xx` responses from non-retryable `4xx` responses.

## Backfill Retry Behavior

`BurrowClient::backfillEvents()` retries transient chunk failures for:

- transport/network failures
- `5xx` responses
- `429` responses (rate limits)

When a `429` includes `Retry-After`, the SDK waits for that delay before the next attempt.
Backfill returns partial accepted/rejected results to the caller so rejected rows are never hidden.
Records with missing or invalid per-event timestamps are validation-rejected before send and surfaced in result summaries.

## Worker Behavior

`OutboxWorker` maps errors to outbox states:

- success (`200/207`) -> `sent`
- retryable (`TransportFailureException` or retryable status exception) -> `retrying`
- exhausted attempts or non-retryable errors -> `failed`
