import { describe, expect, it } from 'vitest';
import { BurrowClient } from '../src/client/BurrowClient.js';
import type { HttpResponse, JsonObject } from '../src/client/types.js';
import type { HttpTransport } from '../src/transport/HttpTransport.js';
import { HttpStatusError } from '../src/transport/errors.js';

class RecordingTransport implements HttpTransport {
  public lastUrl = '';
  public lastHeaders: Record<string, string> = {};
  public lastPayload: JsonObject = {};

  constructor(private readonly response: HttpResponse) {}

  async post(url: string, headers: Record<string, string>, payload: JsonObject): Promise<HttpResponse> {
    this.lastUrl = url;
    this.lastHeaders = headers;
    this.lastPayload = payload;
    return this.response;
  }
}

describe('BurrowClient', () => {
  it('uses expected discover endpoint and auth header', async () => {
    const transport = new RecordingTransport({ status: 200, body: { ok: true }, raw: '{"ok":true}' });
    const client = new BurrowClient({
      baseUrl: 'https://api.example.com',
      apiKey: 'secret_key',
      transport,
    });

    await client.discover({
      site: { url: 'https://example.com' },
      capabilities: { forms: ['freeform'] },
    });

    expect(transport.lastUrl).toBe('https://api.example.com/api/v1/plugin-onboarding/discover');
    expect(transport.lastHeaders).toEqual({ 'x-api-key': 'secret_key' });
    expect(transport.lastPayload).toEqual({
      site: { url: 'https://example.com' },
      capabilities: { forms: ['freeform'] },
    });
  });

  it('routes methods to their endpoint paths', async () => {
    const transport = new RecordingTransport({ status: 207, body: { partial: true }, raw: '{"partial":true}' });
    const client = new BurrowClient({
      baseUrl: 'https://api.example.com/',
      apiKey: 'secret_key',
      transport,
    });

    await client.link({
      site: { url: 'https://example.com' },
      selection: { organizationId: 'org_123' },
    });
    expect(transport.lastUrl).toBe('https://api.example.com/api/v1/plugin-onboarding/link');

    await client.submitFormsContract({ formsContracts: [] });
    expect(transport.lastUrl).toBe('https://api.example.com/api/v1/plugin-onboarding/forms/contracts');

    await client.publishEvent({ event: 'forms.submission.received' });
    expect(transport.lastUrl).toBe('https://api.example.com/api/v1/events');
  });

  it('throws HttpStatusError when publish receives non-accepted status', async () => {
    const transport = new RecordingTransport({
      status: 400,
      body: { error: 'bad request' },
      raw: '{"error":"bad request"}',
    });
    const client = new BurrowClient({
      baseUrl: 'https://api.example.com',
      apiKey: 'secret_key',
      transport,
    });

    await expect(client.publishEvent({ event: 'forms.submission.received' })).rejects.toBeInstanceOf(
      HttpStatusError
    );
  });
});
