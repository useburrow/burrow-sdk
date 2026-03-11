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

  it('fails preflight for forms backfill when ingestion key is missing', async () => {
    const transport = new RecordingBackfillTransport(async () => ({
      status: 200,
      body: { accepted: [], rejected: [] },
      raw: '{"ok":true}',
    }));
    const client = new BurrowClient({
      baseUrl: 'https://api.example.com',
      apiKey: 'bootstrap',
      transport,
      state: { projectId: 'prj_123', projectSourceIds: { forms: 'src_123' } },
    });

    await expect(
      client.backfillEvents({
        channel: 'forms',
        events: [makeBackfillEvent('evt_1', '2026-03-01T12:00:00.000Z')],
        backfill: { windowStart: '2026-03-01T00:00:00.000Z' },
      })
    ).rejects.toMatchObject({ code: 'MISSING_INGESTION_KEY' });
    expect(transport.callCount).toBe(0);
  });

  it('fails preflight for forms backfill when projectId is missing', async () => {
    const transport = new RecordingBackfillTransport(async () => ({
      status: 200,
      body: { accepted: [], rejected: [] },
      raw: '{"ok":true}',
    }));
    const client = new BurrowClient({
      baseUrl: 'https://api.example.com',
      apiKey: 'bootstrap',
      transport,
      state: { ingestionKey: 'ing_key', projectSourceIds: { forms: 'src_123' } },
    });

    await expect(
      client.backfillEvents({
        channel: 'forms',
        events: [makeBackfillEvent('evt_1', '2026-03-01T12:00:00.000Z')],
        backfill: { windowStart: '2026-03-01T00:00:00.000Z' },
      })
    ).rejects.toMatchObject({ code: 'MISSING_PROJECT_ID' });
    expect(transport.callCount).toBe(0);
  });

  it('fails preflight for forms backfill when projectSourceId is missing', async () => {
    const transport = new RecordingBackfillTransport(async () => ({
      status: 200,
      body: { accepted: [], rejected: [] },
      raw: '{"ok":true}',
    }));
    const client = new BurrowClient({
      baseUrl: 'https://api.example.com',
      apiKey: 'bootstrap',
      transport,
      state: { ingestionKey: 'ing_key', projectId: 'prj_123' },
    });

    await expect(
      client.backfillEvents({
        channel: 'forms',
        events: [makeBackfillEvent('evt_1', '2026-03-01T12:00:00.000Z')],
        backfill: { windowStart: '2026-03-01T00:00:00.000Z' },
      })
    ).rejects.toMatchObject({ code: 'MISSING_PROJECT_SOURCE_ID' });
    expect(transport.callCount).toBe(0);
  });

  it('maps 401 to INVALID_INGESTION_API_KEY', async () => {
    const transport = new RecordingBackfillTransport(async () => ({
      status: 401,
      body: { error: { message: 'Invalid key' } },
      raw: '{"error":{"message":"Invalid key"}}',
    }));
    const client = new BurrowClient({
      baseUrl: 'https://api.example.com',
      apiKey: 'bootstrap',
      transport,
      state: { ingestionKey: 'ing_key', projectId: 'prj_123', projectSourceIds: { forms: 'src_123' } },
    });

    await expect(
      client.backfillEvents({
        channel: 'forms',
        events: [makeBackfillEvent('evt_1', '2026-03-01T12:00:00.000Z')],
        backfill: { windowStart: '2026-03-01T00:00:00.000Z' },
      })
    ).rejects.toMatchObject({ code: 'INVALID_INGESTION_API_KEY' });
  });

  it('maps 400 attribution error to FORMS_BACKFILL_ATTRIBUTION_REQUIRED', async () => {
    const transport = new RecordingBackfillTransport(async () => ({
      status: 400,
      body: { error: { code: 'FORMS_BACKFILL_ATTRIBUTION_REQUIRED', message: 'Routing missing.' } },
      raw: '{"error":{"code":"FORMS_BACKFILL_ATTRIBUTION_REQUIRED","message":"Routing missing."}}',
    }));
    const client = new BurrowClient({
      baseUrl: 'https://api.example.com',
      apiKey: 'bootstrap',
      transport,
      state: { ingestionKey: 'ing_key', projectId: 'prj_123', projectSourceIds: { forms: 'src_123' } },
    });

    await expect(
      client.backfillEvents({
        channel: 'forms',
        events: [makeBackfillEvent('evt_1', '2026-03-01T12:00:00.000Z')],
        backfill: { windowStart: '2026-03-01T00:00:00.000Z' },
      })
    ).rejects.toMatchObject({ code: 'FORMS_BACKFILL_ATTRIBUTION_REQUIRED' });
  });

  it('persists link/contracts state and auto-sends forms backfill routing', async () => {
    const transport = new RecordingBackfillTransport(async (callNumber, payload) => {
      if (callNumber === 1) {
        const response: HttpResponse = {
          status: 200,
          body: {
            ingestionKey: { key: 'ingestion_prj_key', scope: 'project', projectId: 'prj_123' },
            project: { id: 'prj_123', clientId: 'cli_123' },
          },
          raw: '{"ok":true}',
        };
        return response;
      }
      if (callNumber === 2) {
        const response: HttpResponse = {
          status: 200,
          body: {
            projectSourceId: 'src_forms_123',
            contractsVersion: 'v1',
            contractMappings: [{ contractId: 'ct_123', enabled: true }],
          },
          raw: '{"ok":true}',
        };
        return response;
      }
      const response: HttpResponse = {
        status: 200,
        body: { accepted: payload.events, rejected: [] },
        raw: '{"ok":true}',
      };
      return response;
    });
    const client = new BurrowClient({
      baseUrl: 'https://api.example.com',
      apiKey: 'bootstrap',
      transport,
    });

    await client.link({
      site: { url: 'https://example.com' },
      selection: { organizationId: 'org_123', projectId: 'prj_123' },
    });
    await client.submitFormsContract({
      platform: 'wordpress',
      formsContracts: [],
      routing: { projectId: 'prj_123' },
    });
    await client.backfillEvents({
      channel: 'forms',
      events: [{ timestamp: '2026-03-01T12:00:00.000Z', source: 'gravity-forms' }],
      backfill: { windowStart: '2026-03-01T00:00:00.000Z' },
    });

    const finalPayload = transport.payloads[2] as JsonObject;
    expect(finalPayload.routing).toEqual({
      projectId: 'prj_123',
      projectSourceId: 'src_forms_123',
      clientId: 'cli_123',
    });
    expect((finalPayload.events as JsonObject[])[0]).toMatchObject({
      channel: 'forms',
      event: 'forms.submission.received',
      source: 'gravity-forms',
    });
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
