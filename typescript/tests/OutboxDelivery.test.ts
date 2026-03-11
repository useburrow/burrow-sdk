import { describe, expect, it } from 'vitest';
import { BurrowClient } from '../src/client/BurrowClient.js';
import type { HttpResponse, JsonObject } from '../src/client/types.js';
import { buildDeterministicEventKey } from '../src/outbox/EventKey.js';
import { OutboxDelivery } from '../src/outbox/OutboxDelivery.js';
import { InMemoryOutboxStore } from '../src/outbox/InMemoryOutboxStore.js';
import { TransportError } from '../src/transport/errors.js';
import type { HttpTransport } from '../src/transport/HttpTransport.js';

class SequenceTransport implements HttpTransport {
  public publishCalls = 0;

  constructor(private readonly sequence: Array<HttpResponse | Error>) {}

  async post(_url: string, _headers: Record<string, string>, _payload: JsonObject): Promise<HttpResponse> {
    this.publishCalls += 1;
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

function createClient(sequence: Array<HttpResponse | Error>): { client: BurrowClient; transport: SequenceTransport } {
  const transport = new SequenceTransport(sequence);
  return {
    client: new BurrowClient({
      baseUrl: 'https://api.example.com',
      apiKey: 'secret_key',
      transport,
    }),
    transport,
  };
}

describe('OutboxDelivery', () => {
  it('dispatchImmediate sends on success', async () => {
    const { client } = createClient([{ status: 200, body: { ok: true }, raw: '{"ok":true}' }]);
    const delivery = new OutboxDelivery(new InMemoryOutboxStore(), client);

    const result = await delivery.dispatchImmediate([makeEvent('dispatch_1')]);

    expect(result.enqueued).toBe(1);
    expect(result.deduped).toBe(0);
    expect(result.sent).toBe(1);
    expect(result.retrying).toBe(0);
    expect(result.failed).toBe(0);
  });

  it('dispatchImmediate dedupes already sent events', async () => {
    const { client } = createClient([{ status: 200, body: { ok: true }, raw: '{"ok":true}' }]);
    const delivery = new OutboxDelivery(new InMemoryOutboxStore(), client);
    const event = makeEvent('dispatch_2');

    const first = await delivery.dispatchImmediate([event]);
    const second = await delivery.dispatchImmediate([event]);

    expect(first.sent).toBe(1);
    expect(second.enqueued).toBe(0);
    expect(second.deduped).toBe(1);
    expect(second.sent).toBe(0);
  });

  it('dispatchImmediate falls back on transport failure', async () => {
    const { client } = createClient([new TransportError('timeout')]);
    const delivery = new OutboxDelivery(new InMemoryOutboxStore(), client, {
      backoffStrategy: { delayMsForAttempt: () => 0 },
    });

    const result = await delivery.dispatchImmediate([makeEvent('dispatch_3')]);
    const stats = await delivery.getOutboxStats();

    expect(result.sent).toBe(0);
    expect(result.retrying).toBe(1);
    expect(result.failed).toBe(0);
    expect(stats.retrying).toBe(1);
  });

  it('dispatchImmediate falls back on non-retryable failure', async () => {
    const { client } = createClient([{ status: 400, body: { error: 'bad request' }, raw: '{"error":"bad request"}' }]);
    const delivery = new OutboxDelivery(new InMemoryOutboxStore(), client);

    const result = await delivery.dispatchImmediate([makeEvent('dispatch_4')]);
    const stats = await delivery.getOutboxStats();

    expect(result.sent).toBe(0);
    expect(result.retrying).toBe(0);
    expect(result.failed).toBe(1);
    expect(stats.failed).toBe(1);
  });

  it('dedupes duplicate enqueue and does not send twice', async () => {
    const { client, transport } = createClient([{ status: 200, body: { ok: true }, raw: '{"ok":true}' }]);
    const delivery = new OutboxDelivery(new InMemoryOutboxStore(), client);
    const event = makeEvent('sub_1');

    const first = await delivery.enqueueEvents([event]);
    const second = await delivery.enqueueEvents([event]);
    const flushed = await delivery.flushOutbox();

    expect(first.enqueued).toBe(1);
    expect(second.deduped).toBe(1);
    expect(flushed.sentCount).toBe(1);
    expect(transport.publishCalls).toBe(1);
  });

  it('retries transient failure then succeeds with single ledger entry', async () => {
    const { client } = createClient([
      new TransportError('timeout'),
      { status: 200, body: { ok: true }, raw: '{"ok":true}' },
    ]);
    const store = new InMemoryOutboxStore();
    const delivery = new OutboxDelivery(store, client, {
      backoffStrategy: { delayMsForAttempt: () => 0 },
    });

    await delivery.enqueueEvents([makeEvent('sub_2')]);
    const firstFlush = await delivery.flushOutbox();
    const secondFlush = await delivery.flushOutbox();
    const stats = await delivery.getOutboxStats();

    expect(firstFlush.retryingCount).toBe(1);
    expect(secondFlush.sentCount).toBe(1);
    expect(stats.sentLedgerCount).toBe(1);
  });

  it('marks non-retryable failures as failed without endless retries', async () => {
    const { client } = createClient([{ status: 400, body: { error: 'bad request' }, raw: '{"error":"bad request"}' }]);
    const delivery = new OutboxDelivery(new InMemoryOutboxStore(), client);
    await delivery.enqueueEvents([makeEvent('sub_3')]);

    const first = await delivery.flushOutbox();
    const second = await delivery.flushOutbox();
    const stats = await delivery.getOutboxStats();

    expect(first.failedCount).toBe(1);
    expect(second.processedCount).toBe(0);
    expect(stats.failed).toBe(1);
  });

  it('replaying same backfill window mostly dedupes', async () => {
    const { client } = createClient([
      { status: 200, body: { ok: true }, raw: '{"ok":true}' },
      { status: 200, body: { ok: true }, raw: '{"ok":true}' },
    ]);
    const delivery = new OutboxDelivery(new InMemoryOutboxStore(), client);
    const windowEvents = [makeEvent('sub_4'), makeEvent('sub_5')];

    const firstRun = await delivery.runBackfillBatch(windowEvents);
    const secondRun = await delivery.runBackfillBatch(windowEvents);

    expect(firstRun.enqueued).toBe(2);
    expect(firstRun.sent).toBe(2);
    expect(secondRun.deduped).toBe(2);
    expect(secondRun.sent).toBe(0);
  });

  it('recovers retrying rows after restart and keeps stable deterministic key', async () => {
    const event = makeEvent('sub_restart');
    const key1 = buildDeterministicEventKey(event);
    const key2 = buildDeterministicEventKey({ ...event });
    expect(key1.eventKey).toBe(key2.eventKey);

    const store = new InMemoryOutboxStore();
    const first = createClient([new TransportError('temporary outage')]);
    const second = createClient([{ status: 200, body: { ok: true }, raw: '{"ok":true}' }]);

    const firstDelivery = new OutboxDelivery(store, first.client, {
      backoffStrategy: { delayMsForAttempt: () => 0 },
    });
    await firstDelivery.enqueueEvents([event]);
    const retryResult = await firstDelivery.flushOutbox();
    expect(retryResult.retryingCount).toBe(1);

    const restartedDelivery = new OutboxDelivery(store, second.client, {
      backoffStrategy: { delayMsForAttempt: () => 0 },
    });
    const recoveredResult = await restartedDelivery.flushOutbox();
    expect(recoveredResult.sentCount).toBe(1);
  });
});

function makeEvent(submissionId: string): JsonObject {
  return {
    channel: 'forms',
    event: 'forms.submission.received',
    source: 'gravity-forms',
    projectId: 'prj_123',
    projectSourceId: 'src_123',
    submissionId,
    timestamp: '2026-03-01T12:00:00.000Z',
  };
}
