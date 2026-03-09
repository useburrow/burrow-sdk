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
