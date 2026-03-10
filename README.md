# Burrow SDKs

Shared SDKs for Burrow plugin integrations (Craft CMS, WordPress, and future platforms).

## Current Scope

- PHP and TypeScript SDK implementations for Craft and WordPress plugins
- Shared onboarding and event contract fixtures
- Durable outbox + retry primitives

## Repository Layout

```text
php/
  src/
  tests/
typescript/
  src/
  tests/
spec/
  contracts/
docs/
.github/workflows/
```

## Phase 1 Delivery

1. Transport client (API key auth + endpoint wrappers)
2. Contract models and validation
3. Event envelope builders
4. Outbox interfaces and SQL implementation

## Local Dev (PHP package)

```bash
cd php
composer install
composer test
```

## Local Dev (TypeScript package)

```bash
cd typescript
npm install
npm run typecheck
npm run test
npm run build
```

## PHP SDK Usage

### Initialize Client

```php
use Burrow\Sdk\Client\BurrowClient;
use Burrow\Sdk\Transport\CurlHttpTransport;
use Burrow\Sdk\Transport\RetryPolicy;

$transport = new CurlHttpTransport(
    timeoutSeconds: 8,
    retryPolicy: new RetryPolicy(maxAttempts: 3, baseDelayMilliseconds: 200)
);

$client = new BurrowClient(
    baseUrl: 'https://api.useburrow.com',
    apiKey: 'your-plugin-api-key',
    transport: $transport
);
```

### Onboarding: Discover

```php
use Burrow\Sdk\Contracts\OnboardingDiscoveryRequest;

$response = $client->discover(new OnboardingDiscoveryRequest(
    site: ['url' => 'https://example.com', 'cmsVersion' => '6.0.0'],
    capabilities: ['forms' => ['freeform']]
));
```

### Onboarding: Link

```php
use Burrow\Sdk\Contracts\OnboardingLinkRequest;

$response = $client->link(new OnboardingLinkRequest(
    site: ['url' => 'https://example.com'],
    selection: ['organizationId' => 'org_123', 'projectId' => 'prj_123']
));
```

### Onboarding: Submit Contracts

```php
use Burrow\Sdk\Contracts\FormsContractSubmissionRequest;

$payload = json_decode(file_get_contents(__DIR__ . '/../spec/contracts/forms-contracts.request.json'), true);
$response = $client->submitFormsContract(new FormsContractSubmissionRequest($payload));
```

### Publish Event

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

$response = $client->publishEvent($event);
```

The normalized event envelope supports lifecycle metadata fields in addition to core routing/event fields:

- `integrationId`, `projectSourceId`, `clientSourceId`
- `icon`, `isLifecycle`, `entityType`
- `externalEntityId`, `externalEventId`, `state`, `stateChangedAt`

Unset optional fields are normalized to `null`, with defaults:

- `schemaVersion: "1"`
- `isLifecycle: false`
- `properties: []` (object-map semantics)
- `tags: []` (object-map semantics)

### Icon Mapping Behavior (Canonical Lucide Names)

`EventEnvelopeBuilder` will auto-resolve `icon` from canonical event/channel mappings when `icon` is not provided.
If `icon` is provided on input, that override wins.

Suggested default mappings include:

- `forms.submission.received` -> `file-signature`
- `ecommerce.order.placed` -> `shopping-cart`
- `ecommerce.item.purchased` -> `package`
- `system.stack.snapshot` -> `layers`
- `system.heartbeat.ping` -> `heart-pulse`

Override guidance:

- optional icon per form/contract mapping metadata
- optional plugin-level event->icon override map

Use Lucide icon key strings from: https://lucide.dev/icons

### Backfill Events (Run After Final Contract Setup)

Run plugin backfill after contracts are finalized in onboarding, not on every per-form save.
Backfill events must include the original source record timestamp per event.

```php
use Burrow\Sdk\Client\BackfillOptions;
use Burrow\Sdk\Contracts\BackfillEventsRequest;
use Burrow\Sdk\Contracts\BackfillWindow;

$result = $client->backfillEvents(
    request: new BackfillEventsRequest(
        events: [$eventA, $eventB],
        backfill: new BackfillWindow(
            windowStart: '2026-03-01T00:00:00.000Z',
            cursor: $lastCursor,
            source: 'wordpress-plugin'
        )
    ),
    options: new BackfillOptions(
        batchSize: 100,
        concurrency: 4,
        maxAttempts: 3
    ),
    progressCallback: static function ($progress): void {
        // queued | running | completed | failed
        error_log(sprintf(
            'Backfill %s: accepted=%d rejected=%d cursor=%s',
            $progress->status,
            $progress->acceptedCount,
            $progress->rejectedCount,
            $progress->latestCursor ?? 'n/a'
        ));
    }
);

// Partial failures are surfaced to caller:
// $result->accepted, $result->rejected, $result->requestedCount, $result->latestCursor
// $result->validationRejectedCount, $result->validationRejections
```

Migration note for plugin consumers: map source created/submitted datetime to `event.timestamp` for every backfilled record.

### Durable Outbox + Worker Loop

```php
use Burrow\Sdk\Outbox\OutboxWorker;
use Burrow\Sdk\Outbox\SqlOutboxStore;

$pdo = new PDO($dsn, $user, $password);
$store = new SqlOutboxStore($pdo);

$store->enqueue(
    eventKey: 'forms:contact:sub_123',
    payload: $event
);

$worker = new OutboxWorker($store, $client, maxAttempts: 5);

while (true) {
    $result = $worker->runOnce(limit: 100);
    if ($result->processedCount === 0) {
        sleep(2);
    }
}
```

### PHP Idempotency Recommendation (`eventKey`)

Use a deterministic `eventKey` so retries and duplicate submissions can be safely de-duplicated.
It is fine to use different naming conventions per plugin, as long as the same real-world event always produces the same key.

Recommended patterns:

- forms submission: `forms:<formId>:<submissionId>`
- order placed: `ecommerce:order:<orderId>`
- item purchased: `ecommerce:item:<orderId>:<lineItemId>`

## Versioning

SemVer per package. Breaking contract changes require major bump and migration notes.
