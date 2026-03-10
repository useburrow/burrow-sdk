import { describe, expect, it } from 'vitest';
import { BurrowClient } from '../src/client/BurrowClient.js';
import { toBackfillPayload, type BackfillProgressUpdate } from '../src/client/BackfillTypes.js';
import type { HttpResponse, JsonObject } from '../src/client/types.js';
import type { HttpTransport } from '../src/transport/HttpTransport.js';

class RecordingBackfillTransport implements HttpTransport {
  public readonly payloads: JsonObject[] = [];
  public inFlight = 0;
  public maxInFlight = 0;
  public callCount = 0;

  constructor(private readonly responder: (callNumber: number, payload: JsonObject) => Promise<HttpResponse>) {}

  async post(_url: string, _headers: Record<string, string>, payload: JsonObject): Promise<HttpResponse> {
    this.callCount += 1;
    this.payloads.push(payload);
    this.inFlight += 1;
    this.maxInFlight = Math.max(this.maxInFlight, this.inFlight);
    try {
      return await this.responder(this.callCount, payload);
    } finally {
      this.inFlight -= 1;
    }
  }
}

describe('BurrowClient backfillEvents', () => {
  it('serializes backfill payload shape', () => {
    const payload = toBackfillPayload({
      events: [makeBackfillEvent('evt_1', '2026-03-01T12:00:00.000Z')],
      backfill: {
        cursor: 'cursor_123',
        windowStart: '2026-03-01T00:00:00.000Z',
        windowEnd: '2026-03-02T00:00:00.000Z',
        source: 'wordpress-plugin',
      },
    });

    expect(payload.backfill.cursor).toBe('cursor_123');
    expect(payload.backfill.windowStart).toBe('2026-03-01T00:00:00.000Z');
  });

  it('chunks by 100 and defaults to concurrency 4', async () => {
    const transport = new RecordingBackfillTransport(async (_callNumber, payload) => {
      await new Promise((resolve) => setTimeout(resolve, 1));
      const events = Array.isArray(payload.events) ? payload.events : [];
      return {
        status: 200,
        body: { accepted: events, rejected: [], backfill: { cursor: 'cursor_next' } },
        raw: '{"ok":true}',
      };
    });
    const client = new BurrowClient({
      baseUrl: 'https://api.example.com',
      apiKey: 'secret',
      transport,
    });

    const events = Array.from({ length: 401 }, (_, index) => makeBackfillEvent(`evt_${index + 1}`, '2026-03-01T12:00:00.000Z'));
    await client.backfillEvents({
      events,
      backfill: { windowStart: '2026-03-01T00:00:00.000Z' },
    });

    expect(transport.payloads).toHaveLength(5);
    expect((transport.payloads[0]?.events as JsonObject[] | undefined)?.length).toBe(100);
    expect((transport.payloads[4]?.events as JsonObject[] | undefined)?.length).toBe(1);
    expect(transport.maxInFlight).toBe(4);
  });

  it('retries on 429 and honors retry-after', async () => {
    const transport = new RecordingBackfillTransport(async (callNumber, payload) => {
      const events = Array.isArray(payload.events) ? payload.events : [];
      if (callNumber === 1) {
        const response: HttpResponse = {
          status: 429,
          body: { error: 'rate limit', accepted: [], rejected: [] },
          raw: '{"error":"rate limit"}',
          headers: { 'retry-after': '0' },
        };
        return response;
      }

      const response: HttpResponse = {
        status: 200,
        body: { accepted: events, rejected: [], error: null },
        raw: '{"ok":true}',
      };
      return response;
    });
    const client = new BurrowClient({
      baseUrl: 'https://api.example.com',
      apiKey: 'secret',
      transport,
    });

    const result = await client.backfillEvents(
      {
        events: [makeBackfillEvent('evt_1', '2026-03-01T12:00:00.000Z')],
        backfill: { windowStart: '2026-03-01T00:00:00.000Z' },
      },
      {
        retry: { maxAttempts: 3, baseDelayMs: 1, maxDelayMs: 1 },
        sleepFn: async () => {},
      }
    );

    expect(transport.callCount).toBe(2);
    expect(result.summary.acceptedCount).toBe(1);
    expect(result.summary.validationRejectedCount).toBe(0);
  });

  it('rejects missing timestamp and continues with valid records', async () => {
    const transport = new RecordingBackfillTransport(async (_callNumber, payload) => {
      const events = Array.isArray(payload.events) ? payload.events : [];
      return {
        status: 200,
        body: { accepted: events, rejected: [] },
        raw: '{"ok":true}',
      };
    });
    const client = new BurrowClient({
      baseUrl: 'https://api.example.com',
      apiKey: 'secret',
      transport,
    });

    const result = await client.backfillEvents({
      events: [
        makeBackfillEvent('evt_valid', '2026-03-01T12:00:00.000Z'),
        { externalEventId: 'evt_missing', event: 'forms.submission.received' },
      ],
        backfill: { windowStart: '2026-03-01T00:00:00.000Z' },
    });

    expect(transport.callCount).toBe(1);
    expect((transport.payloads[0]?.events as JsonObject[]).length).toBe(1);
    expect(result.summary.validationRejectedCount).toBe(1);
    expect(result.validationRejections?.[0]?.reason).toBe('missing_timestamp');
  });

  it('rejects malformed timestamp and does not fallback to now', async () => {
    const transport = new RecordingBackfillTransport(async (_callNumber, payload) => {
      const events = Array.isArray(payload.events) ? payload.events : [];
      return {
        status: 200,
        body: { accepted: events, rejected: [] },
        raw: '{"ok":true}',
      };
    });
    const client = new BurrowClient({
      baseUrl: 'https://api.example.com',
      apiKey: 'secret',
      transport,
    });

    const result = await client.backfillEvents({
      events: [
        makeBackfillEvent('evt_valid', '2026-03-01T12:00:00.000Z'),
        makeBackfillEvent('evt_invalid', 'not-a-date'),
      ],
      backfill: { windowStart: '2026-03-01T00:00:00.000Z' },
    });

    const sentEvents = transport.payloads[0]?.events as JsonObject[];
    expect(sentEvents.length).toBe(1);
    expect(sentEvents[0]?.timestamp).toBe('2026-03-01T12:00:00.000Z');
    expect(result.summary.validationRejectedCount).toBe(1);
    expect(result.validationRejections?.[0]?.reason).toBe('invalid_timestamp');
  });

  it('returns partial accepted and rejected with progress callbacks', async () => {
    const progress: BackfillProgressUpdate[] = [];
    const transport = new RecordingBackfillTransport(async () => ({
      status: 207,
      body: {
        accepted: [{ externalEventId: 'evt_1' }],
        rejected: [{ externalEventId: 'evt_2', reason: 'invalid' }],
        backfill: { cursor: 'cursor_final' },
      },
      raw: '{"partial":true}',
    }));
    const client = new BurrowClient({
      baseUrl: 'https://api.example.com',
      apiKey: 'secret',
      transport,
    });

    const result = await client.backfillEvents(
      {
        events: [
          makeBackfillEvent('evt_1', '2026-03-01T12:00:00.000Z'),
          makeBackfillEvent('evt_2', '2026-03-01T12:01:00.000Z'),
        ],
        backfill: { windowStart: '2026-03-01T00:00:00.000Z' },
      },
      {
        onProgress: (update) => progress.push(update),
      }
    );

    expect(result.summary.requestedCount).toBe(2);
    expect(result.summary.acceptedCount).toBe(1);
    expect(result.summary.rejectedCount).toBe(1);
    expect(result.summary.validationRejectedCount).toBe(0);
    expect(result.latestCursor).toBe('cursor_final');
    expect(progress.map((p) => p.status)).toEqual(expect.arrayContaining(['queued', 'running', 'completed']));
  });
});

function makeBackfillEvent(externalEventId: string, timestamp: string): JsonObject {
  return {
    organizationId: 'org_123',
    clientId: 'cli_123',
    channel: 'forms',
    event: 'forms.submission.received',
    externalEventId,
    timestamp,
  };
}
