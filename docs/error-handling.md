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

## Worker Behavior

`OutboxWorker` maps errors to outbox states:

- success (`200/207`) -> `sent`
- retryable (`TransportFailureException` or retryable status exception) -> `retrying`
- exhausted attempts or non-retryable errors -> `failed`
