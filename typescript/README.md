# Burrow TypeScript SDK

Framework-agnostic TypeScript/Node SDK for Burrow plugin integrations.

## Install

```bash
npm install @useburrow/sdk-typescript
```

## Build and Test

```bash
npm install
npm run typecheck
npm run test
npm run build
```

## Usage

### Initialize Client

```ts
import { BurrowClient, FetchTransport } from '@useburrow/sdk-typescript';

const client = new BurrowClient({
  baseUrl: 'https://api.useburrow.com',
  apiKey: process.env.BURROW_API_KEY ?? '',
  transport: new FetchTransport({
    timeoutMs: 8_000,
    retryPolicy: { maxAttempts: 3, baseDelayMs: 200, maxDelayMs: 2_000 },
  }),
});
```

### Onboarding: Discover / Link / Contracts

```ts
await client.discover({
  site: { url: 'https://example.com', cmsVersion: '6.0.0' },
  capabilities: { forms: ['freeform'] },
});

await client.link({
  site: { url: 'https://example.com' },
  selection: { organizationId: 'org_123', projectId: 'prj_123' },
});

await client.submitFormsContract({
  platform: 'craft',
  pluginVersion: '1.0.0',
  site: { url: 'https://example.com' },
  formsContracts: [],
});
```

### Publish Event

```ts
import { EventEnvelopeBuilder } from '@useburrow/sdk-typescript';

const event = EventEnvelopeBuilder.build({
  organizationId: 'org_123',
  clientId: 'cli_123',
  channel: 'forms',
  event: 'forms.submission.received',
  timestamp: new Date().toISOString(),
  properties: { submissionId: 'sub_123' },
  tags: { formId: 'contact' },
});

await client.publishEvent(event);
```

The builder normalizes optional canonical fields to `null` and applies defaults:

- `schemaVersion: "1"`
- `isLifecycle: false`
- `properties: {}`
- `tags: {}`

Lifecycle/correlation fields are supported directly in the envelope:

- `integrationId`, `projectSourceId`, `clientSourceId`
- `icon`, `entityType`, `externalEntityId`, `externalEventId`
- `state`, `stateChangedAt`

### Icon Mapping Behavior

If `icon` is omitted, the SDK resolves a canonical Lucide icon from event/channel mappings.
If `icon` is provided, explicit override is preserved.

Example defaults:

- `forms.submission.received` -> `file-signature`
- `ecommerce.order.placed` -> `shopping-cart`
- `ecommerce.item.purchased` -> `package`
- `system.stack.snapshot` -> `layers`
- `system.heartbeat.ping` -> `heart-pulse`

Recommended override points:

- per-contract/form mapping metadata
- plugin-level event override map

Choose Lucide icon names from https://lucide.dev/icons and send the icon key string (for example `shopping-cart`, `file-signature`, `layers`).

### Backfill Events (Run After Final Contract Setup)

Run backfill as the final onboarding wizard step after contracts are configured, not on every per-form save.
Backfill events must include the original source record timestamp per event.

```ts
const result = await client.backfillEvents(
  {
    events: historicalEvents,
    backfill: {
      windowStart: '2026-03-01T00:00:00.000Z',
      cursor: lastCursor,
      source: 'wordpress-plugin',
    },
  },
  {
    batchSize: 100,
    concurrency: 4,
    retry: { maxAttempts: 3, baseDelayMs: 200, maxDelayMs: 2_000 },
    onProgress: (progress) => {
      // queued | running | completed | failed
      console.log(progress.status, progress.acceptedCount, progress.rejectedCount, progress.latestCursor);
    },
  }
);

console.log(result.summary, result.accepted.length, result.rejected.length);
```

Validation summary fields:

- `result.summary.validationRejectedCount`
- `result.validationRejections` (index + reason + message)

Migration note for plugin consumers: map source created/submitted datetime to `event.timestamp` for each backfilled record.

### In-Memory Outbox + Worker Loop

```ts
import { InMemoryOutboxStore, OutboxWorker } from '@useburrow/sdk-typescript';

const outbox = new InMemoryOutboxStore();
const worker = new OutboxWorker(outbox, client, { maxAttempts: 5, batchSize: 100 });

outbox.enqueue('forms:contact:sub_123', event);

while (true) {
  const result = await worker.runOnce();
  if (result.processedCount === 0) {
    await new Promise((resolve) => setTimeout(resolve, 2_000));
  }
}
```

### Idempotency Recommendation (`eventKey`)

Use a deterministic `eventKey` so retries and duplicate submissions can be safely de-duplicated.
Good `eventKey` candidates combine stable business identifiers:

- forms: `<channel>:<formId>:<submissionId>`
- ecommerce order: `<channel>:order:<orderId>`
- ecommerce line item: `<channel>:item:<orderId>:<lineItemId>`

Example:

```ts
const eventKey = `forms:${event.tags.formId}:${event.properties.submissionId}`;
outbox.enqueue(eventKey, event);
```

### SQL Outbox Store (Adapter-Based)

`SqlOutboxStore` is framework-agnostic and requires a small adapter for your SQL driver.

```ts
import type { SqlOutboxAdapter } from '@useburrow/sdk-typescript';
import { OutboxWorker, SqlOutboxStore } from '@useburrow/sdk-typescript';

const adapter: SqlOutboxAdapter = {
  async execute(statement, params) {
    await db.execute(statement, params);
  },
  async query(statement, params) {
    return db.query(statement, params);
  },
};

const outbox = new SqlOutboxStore(adapter);
const worker = new OutboxWorker(outbox, client, { maxAttempts: 5 });
```

Use the shared schema/migration guidance in `docs/outbox-schema.md`.
