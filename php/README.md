# Burrow PHP SDK

Official PHP SDK for integrating WordPress, Craft, Statamic,
ExpressionEngine, and other PHP plugin ecosystems or frameworks with the
Burrow platform.

## What Burrow Does

Burrow helps plugin teams turn product activity into reliable, structured
signals that power onboarding visibility, operational automation, and product
analytics.

With Burrow, plugins can:

- discover and link tenant/project context
- publish canonical events across forms, ecommerce, and system channels
- keep historical backfills aligned with real source timestamps
- route data safely with scoped ingestion keys and project guards
- use retry + outbox primitives for durable delivery

## Why Use This SDK

This SDK provides a framework-agnostic integration layer so plugin code can stay
focused on CMS specifics while Burrow concerns stay centralized:

- typed client for onboarding, contracts, events, and backfill APIs
- canonical event envelope builders and validation helpers
- retry-aware transport and normalized API error classification
- in-memory and SQL outbox building blocks for resilient event dispatch

## Installation

```bash
composer require useburrow/sdk-php
```

## Requirements

- PHP 8.2+

## Quick Start

```php
use Burrow\Sdk\Client\BurrowClient;
use Burrow\Sdk\Transport\CurlHttpTransport;
use Burrow\Sdk\Transport\RetryPolicy;

$transport = new CurlHttpTransport(
    timeoutSeconds: 8,
    retryPolicy: new RetryPolicy(maxAttempts: 3, baseDelayMilliseconds: 200)
);

$client = new BurrowClient(
    baseUrl: 'https://app.useburrow.com',
    apiKey: 'your-plugin-api-key',
    transport: $transport
);
```

## Typical Plugin Flow

1. Call onboarding discover/link during plugin setup.
2. Submit/fetch forms contracts and persist mapping metadata.
3. Publish real-time canonical events as plugin activity occurs.
4. Run historical backfill after contract setup is finalized.
5. Use outbox delivery for idempotent retries and outage resilience.

## Example: Publish an Event

```php
use Burrow\Sdk\Events\EventEnvelopeBuilder;

$event = EventEnvelopeBuilder::build([
    'organizationId' => 'org_123',
    'clientId' => 'cli_123',
    'channel' => 'forms',
    'event' => 'forms.submission.received',
    'timestamp' => gmdate('c'),
    'properties' => ['submissionId' => 'sub_123'],
    'tags' => ['formId' => 'contact'],
]);

$client->publishEvent($event);
```

## Development

```bash
composer install
composer test
```

## License

MIT. See `LICENSE`.
