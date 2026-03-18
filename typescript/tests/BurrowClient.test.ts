import { describe, expect, it } from 'vitest';
import { BurrowClient } from '../src/client/BurrowClient.js';
import type { HttpResponse, JsonObject } from '../src/client/types.js';
import type { HttpTransport } from '../src/transport/HttpTransport.js';
import { SdkApiError } from '../src/transport/errors.js';

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

class QueueRecordingTransport implements HttpTransport {
  public lastUrl = '';
  public lastHeaders: Record<string, string> = {};
  public lastPayload: JsonObject = {};

  constructor(private readonly responses: HttpResponse[]) {}

  async post(url: string, headers: Record<string, string>, payload: JsonObject): Promise<HttpResponse> {
    this.lastUrl = url;
    this.lastHeaders = headers;
    this.lastPayload = payload;
    const response = this.responses.shift();
    if (!response) {
      throw new Error('No response left in queue.');
    }
    return response;
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

    await client.fetchFormsContracts('prj_123', 'craft');
    expect(transport.lastUrl).toBe('https://api.example.com/api/v1/plugin-onboarding/forms/contracts/fetch');

    await client.publishEvent({ event: 'forms.submission.received' });
    expect(transport.lastUrl).toBe('https://api.example.com/api/v1/events');

    await client.backfillEvents({
      events: [{ event: 'forms.submission.received', timestamp: '2026-03-01T12:00:00.000Z' }],
      backfill: { windowStart: '2026-03-01T00:00:00.000Z' },
    });
    expect(transport.lastUrl).toBe('https://api.example.com/api/v1/plugin-backfill/events');
  });

  it('supports optional platform and capabilities for link', async () => {
    const transport = new RecordingTransport({ status: 200, body: { ok: true }, raw: '{"ok":true}' });
    const client = new BurrowClient({
      baseUrl: 'https://api.example.com',
      apiKey: 'secret_key',
      transport,
    });

    await client.link({
      site: { url: 'https://example.com' },
      selection: { organizationId: 'org_123', projectId: 'prj_123' },
      platform: 'wordpress',
      capabilities: {
        forms: ['gravity-forms'],
        ecommerce: ['woocommerce'],
        system: true,
      },
    });

    expect(transport.lastPayload).toEqual({
      site: { url: 'https://example.com' },
      selection: { organizationId: 'org_123', projectId: 'prj_123' },
      platform: 'wordpress',
      capabilities: {
        forms: ['gravity-forms'],
        ecommerce: ['woocommerce'],
        system: true,
      },
    });
  });

  it('throws SdkApiError when publish receives non-accepted status', async () => {
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

    await expect(client.publishEvent({ event: 'forms.submission.received' })).rejects.toBeInstanceOf(SdkApiError);
  });

  it('parses link response deep-link and switches to scoped ingestion key', async () => {
    const transport = new QueueRecordingTransport([
      {
        status: 200,
        body: {
          routing: { projectId: 'prj_123' },
          ingestionKey: {
            key: 'burrow_prj_key_abc',
            keyPrefix: 'burrow_prj',
            scope: 'project',
            projectId: 'prj_123',
          },
          project: {
            id: 'prj_123',
            name: 'Anysizebasket',
            slug: 'anysizebasket-com',
            clientId: 'cli_123',
            clientName: 'Three M Tool',
            clientSlug: 'three-m-tool',
            burrowProjectPath: '/clients/three-m-tool/projects/anysizebasket-com',
            burrowProjectUrl: 'https://app.useburrow.com/clients/three-m-tool/projects/anysizebasket-com',
          },
        },
        raw: '{"ok":true}',
      },
      { status: 200, body: { ok: true }, raw: '{"ok":true}' },
    ]);
    const client = new BurrowClient({
      baseUrl: 'https://api.example.com',
      apiKey: 'bootstrap_key',
      transport,
    });

    const link = await client.link({
      site: { url: 'https://example.com' },
      selection: { organizationId: 'org_123', projectId: 'prj_123' },
    });

    expect(link.ingestionKey?.scope).toBe('project');
    expect(link.ingestionKey?.projectId).toBe('prj_123');
    expect(link.project?.burrowProjectPath).toBe('/clients/three-m-tool/projects/anysizebasket-com');
    expect(client.getLinkedProjectDeepLink()).toEqual({
      path: '/clients/three-m-tool/projects/anysizebasket-com',
      url: 'https://app.useburrow.com/clients/three-m-tool/projects/anysizebasket-com',
    });

    await client.publishEvent({
      projectId: 'prj_123',
      event: 'forms.submission.received',
    });

    expect(transport.lastHeaders).toEqual({ 'x-api-key': 'burrow_prj_key_abc' });
  });

  it('requires projectId when scoped key is active', async () => {
    const transport = new RecordingTransport({
      status: 200,
      body: {
        routing: { projectId: 'prj_123' },
        ingestionKey: { key: 'burrow_prj_key_abc', scope: 'project', projectId: 'prj_123' },
      },
      raw: '{"ok":true}',
    });
    const client = new BurrowClient({
      baseUrl: 'https://api.example.com',
      apiKey: 'bootstrap_key',
      transport,
    });
    await client.link({
      site: { url: 'https://example.com' },
      selection: { organizationId: 'org_123', projectId: 'prj_123' },
    });

    await expect(client.publishEvent({ event: 'forms.submission.received' })).rejects.toThrow(
      'projectId is required when using a project-scoped ingestion key.'
    );
  });

  it('rejects mismatched scoped project for publish/forms fetch/forms submit', async () => {
    const transport = new RecordingTransport({
      status: 200,
      body: {
        routing: { projectId: 'prj_123' },
        ingestionKey: { key: 'burrow_prj_key_abc', scope: 'project', projectId: 'prj_123' },
      },
      raw: '{"ok":true}',
    });
    const client = new BurrowClient({
      baseUrl: 'https://api.example.com',
      apiKey: 'bootstrap_key',
      transport,
    });
    await client.link({
      site: { url: 'https://example.com' },
      selection: { organizationId: 'org_123', projectId: 'prj_123' },
    });

    await expect(
      client.publishEvent({
        projectId: 'prj_999',
        event: 'forms.submission.received',
      })
    ).rejects.toThrow('does not match scoped key project');

    await expect(client.fetchFormsContracts('prj_999', 'wordpress')).rejects.toThrow(
      'Cannot forms contracts fetch'
    );

    await expect(
      client.submitFormsContract({
        platform: 'wordpress',
        routing: { projectId: 'prj_999' },
        formsContracts: [],
      })
    ).rejects.toThrow('Cannot forms contracts');
  });
});
