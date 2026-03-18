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
    baseUrl: 'https://app.useburrow.com',
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

$link = $client->link(new OnboardingLinkRequest(
    site: ['url' => 'https://example.com'],
    selection: ['organizationId' => 'org_123', 'projectId' => 'prj_123']
));

// SDK stores project-scoped ingestion key returned from link,
// and uses it for subsequent plugin event/forms API calls.
// Access project deep-link for plugin settings:
$deepLink = $client->getLinkedProjectDeepLink();
// $deepLink?->path, $deepLink?->url
```

### Onboarding: Submit Contracts

```php
use Burrow\Sdk\Contracts\FormsContractSubmissionRequest;

$payload = json_decode(file_get_contents(__DIR__ . '/../spec/contracts/forms-contracts.request.json'), true);
$contracts = $client->submitFormsContract(new FormsContractSubmissionRequest($payload));

// Persist these for contract ID roundtrips:
// - $contracts->projectSourceId
// - $contracts->contractsVersion
// - $contracts->contractMappings (contractId + form identifiers)
```

To rehydrate latest mappings later (for reconnect/reconcile), call fetch:

```php
$latest = $client->fetchFormsContracts(projectId: 'prj_123', platform: 'craft');
```

To build a plugin-local lookup map from either response:

```php
use Burrow\Sdk\Contracts\FormsContractCache;
use Burrow\Sdk\Contracts\FormsContractCacheReconciler;

$cache = FormsContractCache::fromResponse('prj_123', $contracts);
$result = FormsContractCacheReconciler::reconcile($cache, $latest, 'prj_123');

if ($result->updated) {
    // save refreshed cache when contractsVersion changed
}
```

The SDK also includes persistence primitives to help plugin agents stay framework-agnostic:

- `FormsContractCacheRepositoryInterface` for storage adapters
- `FormsContractCacheSerializer` for JSON/object conversion
- `InMemoryFormsContractCacheRepository` as a reference implementation for tests/dev

Recommended plugin adapter pattern:

- WordPress: implement repository via options table or custom table
- Craft: implement repository via project config or plugin table
- Future platforms: implement the same interface without changing SDK client code

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

Prefer SDK canonical builders for system/ecommerce payloads so plugins do not drift from Burrow contract shape:

- `CanonicalEnvelopeBuilders::buildSystemStackSnapshotEvent(...)`
- `CanonicalEnvelopeBuilders::buildSystemHeartbeatEvent(...)`
- `CanonicalEnvelopeBuilders::buildEcommerceOrderPlacedEvent(...)`
- `CanonicalEnvelopeBuilders::buildEcommerceItemPurchasedEvent(...)`

Migration note for plugin agents: replace hand-rolled envelope arrays with canonical builders + `publishEvent`/`backfillEvents`.
Canonical event names use channel-prefixed three-segment notation (for example `system.stack.snapshot`, `ecommerce.order.placed`).

The normalized event envelope supports lifecycle metadata fields in addition to core routing/event fields:

- `integrationId`, `projectSourceId`, `clientSourceId`
- `icon`, `isLifecycle`, `entityType`
- `externalEntityId`, `externalEventId`, `state`, `stateChangedAt`

`source` now captures the actual origin provider for forms/ecommerce when available
(for example `gravity-forms`, `fluent-forms`, `woocommerce`, `craft-commerce`)
instead of always using a generic platform label.
System events keep platform-level source defaults unless explicitly overridden.

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

### Source Mapping Behavior (Provider Origin)

`EventEnvelopeBuilder` auto-resolves `source` with this precedence:

1. explicit `source` input (override wins)
2. provider-specific source for `forms.*` / `ecommerce.*` events when provider is known
3. platform fallback (`wordpress-plugin` by default, `craft-plugin` when platform is `craft`)

Provider source values use Burrow slug conventions: lowercase and hyphenated.

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
Migration note for contract roundtrip: persist `projectSourceId`, `contractsVersion`, and form mapping keys
(`externalFormId|formHandle`) so plugin forms can reconcile to canonical Burrow `contractId` on future runs.
Migration note for key scope: after onboarding link, use the returned project-scoped ingestion key.
When scoped key is active, SDK enforces `projectId` for events and project-matching guards for forms contracts/fetch.

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

## Migration Notes (SDK hardening)

- `BurrowClient` now persists onboarding/contracts runtime state (`ingestionKey`, `projectId`, forms `projectSourceId`, `contractsVersion`, `contractMappings`).
- New helpers are available in both SDKs:
  - `getProjectId()`
  - `getProjectSourceId('forms')`
  - `getBackfillRouting('forms')`
- Forms backfill now enforces SDK preflight before network calls:
  - `MISSING_INGESTION_KEY`
  - `MISSING_PROJECT_ID`
  - `MISSING_PROJECT_SOURCE_ID`
- Backfill payloads are normalized by SDK and include routing automatically for forms:
  - `routing.projectId`
  - `routing.projectSourceId`
  - `channel='forms'`, `event='forms.submission.received'` defaults
- Non-2xx API responses are normalized into typed SDK errors with retryability metadata.
- Retry behavior now treats `400/401/403` as non-retryable and `429/5xx/network` as retryable.
