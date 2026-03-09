import { describe, expect, it } from 'vitest';
import { FetchTransport } from '../src/transport/FetchTransport.js';
import { InvalidJsonResponseError, TransportError } from '../src/transport/errors.js';
import type { JsonObject } from '../src/client/types.js';

function createResponse(status: number, body: string): Response {
  return new Response(body, { status, headers: { 'content-type': 'application/json' } });
}

describe('FetchTransport retries', () => {
  it('retries transport failures and eventually succeeds', async () => {
    let calls = 0;
    const transport = new FetchTransport({
      retryPolicy: { maxAttempts: 3, baseDelayMs: 1, maxDelayMs: 2 },
      sleepFn: async () => {},
      fetchImpl: async () => {
        calls += 1;
        if (calls < 3) {
          throw new Error('network down');
        }
        return createResponse(200, '{"ok":true}');
      },
    });

    const response = await transport.post('https://example.com', {}, {} as JsonObject);
    expect(response.status).toBe(200);
    expect(calls).toBe(3);
  });

  it('retries 5xx responses up to max attempts', async () => {
    let calls = 0;
    const transport = new FetchTransport({
      retryPolicy: { maxAttempts: 2, baseDelayMs: 1, maxDelayMs: 2 },
      sleepFn: async () => {},
      fetchImpl: async () => {
        calls += 1;
        return createResponse(503, '{"error":"unavailable"}');
      },
    });

    const response = await transport.post('https://example.com', {}, {} as JsonObject);
    expect(response.status).toBe(503);
    expect(calls).toBe(2);
  });

  it('does not wrap invalid JSON as transport error', async () => {
    const transport = new FetchTransport({
      fetchImpl: async () => createResponse(200, 'not-json'),
    });

    await expect(transport.post('https://example.com', {}, {} as JsonObject)).rejects.toBeInstanceOf(
      InvalidJsonResponseError
    );
  });

  it('throws TransportError after exhausting transport retries', async () => {
    const transport = new FetchTransport({
      retryPolicy: { maxAttempts: 2, baseDelayMs: 1, maxDelayMs: 2 },
      sleepFn: async () => {},
      fetchImpl: async () => {
        throw new Error('network down');
      },
    });

    await expect(transport.post('https://example.com', {}, {} as JsonObject)).rejects.toBeInstanceOf(
      TransportError
    );
  });
});
