import { describe, expect, it } from 'vitest';
import { BurrowClient } from '../src/client/BurrowClient.js';
import type { HttpResponse, JsonObject } from '../src/client/types.js';
import { InMemoryOutboxStore } from '../src/outbox/InMemoryOutboxStore.js';
import { OutboxWorker } from '../src/outbox/OutboxWorker.js';
import { HttpStatusError, TransportError } from '../src/transport/errors.js';
import type { HttpTransport } from '../src/transport/HttpTransport.js';

class SequenceTransport implements HttpTransport {
  constructor(private readonly sequence: Array<HttpResponse | Error>) {}

  async post(_url: string, _headers: Record<string, string>, _payload: JsonObject): Promise<HttpResponse> {
    const item = this.sequence.shift();
    if (!item) {
      throw new Error('No sequence item left.');
    }

    if (item instanceof Error) {
      throw item;
    }

    return item;
  }
}

function createClient(sequence: Array<HttpResponse | Error>): BurrowClient {
  return new BurrowClient({
    baseUrl: 'https://api.example.com',
    apiKey: 'secret_key',
    transport: new SequenceTransport(sequence),
  });
}

describe('OutboxWorker', () => {
  it('marks events sent on success status', async () => {
    const store = new InMemoryOutboxStore();
    const record = store.enqueue('event_1', { event: 'forms.submission.received' }).record!;
    const worker = new OutboxWorker(store, createClient([{ status: 200, body: { ok: true }, raw: '{"ok":true}' }]));

    const result = await worker.runOnce();
    const updated = store.getById(record.id);

    expect(result.sentCount).toBe(1);
    expect(updated?.status).toBe('sent');
    expect(updated?.attemptCount).toBe(1);
  });

  it('marks retrying for retryable transport failures', async () => {
    const store = new InMemoryOutboxStore();
    const record = store.enqueue('event_1', { event: 'forms.submission.received' }).record!;
    const worker = new OutboxWorker(store, createClient([new TransportError('timeout')]));

    const result = await worker.runOnce();
    const updated = store.getById(record.id);

    expect(result.retryingCount).toBe(1);
    expect(updated?.status).toBe('retrying');
    expect(updated?.attemptCount).toBe(1);
    expect(updated?.nextAttemptAt).not.toBeNull();
  });

  it('marks failed when max attempts are exhausted', async () => {
    const store = new InMemoryOutboxStore();
    const record = store.enqueue('event_1', { event: 'forms.submission.received' }).record!;
    store.markRetrying(record.id, 'attempt one failed');
    store.markRetrying(record.id, 'attempt two failed');

    const worker = new OutboxWorker(
      store,
      createClient([new HttpStatusError('/api/v1/events', 503, { error: 'unavailable' }, '{"error":"unavailable"}')]),
      {
        maxAttempts: 3,
      }
    );

    const result = await worker.runOnce();
    const updated = store.getById(record.id);

    expect(result.failedCount).toBe(1);
    expect(updated?.status).toBe('failed');
    expect(updated?.attemptCount).toBe(3);
  });

  it('marks failed for non-retryable errors', async () => {
    const store = new InMemoryOutboxStore();
    const record = store.enqueue('event_1', { event: 'forms.submission.received' }).record!;
    const worker = new OutboxWorker(
      store,
      createClient([new HttpStatusError('/api/v1/events', 400, { error: 'invalid' }, '{"error":"invalid"}')])
    );

    await worker.runOnce();
    const updated = store.getById(record.id);

    expect(updated?.status).toBe('failed');
    expect(updated?.attemptCount).toBe(1);
  });

  it('marks sent without publishing when skipNetworkSend enabled', async () => {
    const store = new InMemoryOutboxStore();
    const record = store.enqueue('event_1', { event: 'forms.submission.received' }).record!;
    const worker = new OutboxWorker(
      store,
      createClient([new Error('should not be called')]),
      { skipNetworkSend: true }
    );

    const result = await worker.runOnce();
    const updated = store.getById(record.id);

    expect(result.sentCount).toBe(1);
    expect(result.retryingCount).toBe(0);
    expect(result.failedCount).toBe(0);
    expect(updated?.status).toBe('sent');
    expect(updated?.attemptCount).toBe(1);
  });
});
