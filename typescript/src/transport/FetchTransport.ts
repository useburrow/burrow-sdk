import type { HttpResponse, JsonObject } from '../client/types.js';
import type { HttpTransport } from './HttpTransport.js';
import { InvalidJsonResponseError, TransportError } from './errors.js';

export interface FetchTransportOptions {
  timeoutMs?: number;
  fetchImpl?: typeof fetch;
}

export class FetchTransport implements HttpTransport {
  private readonly timeoutMs: number;
  private readonly fetchImpl: typeof fetch;

  constructor(options: FetchTransportOptions = {}) {
    this.timeoutMs = options.timeoutMs ?? 5_000;
    this.fetchImpl = options.fetchImpl ?? globalThis.fetch;
  }

  async post(url: string, headers: Record<string, string>, payload: JsonObject): Promise<HttpResponse> {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), this.timeoutMs);

    try {
      const response = await this.fetchImpl(url, {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          ...headers,
        },
        body: JSON.stringify(payload),
        signal: controller.signal,
      });

      const raw = await response.text();
      if (raw === '') {
        return { status: response.status, body: null, raw };
      }

      try {
        const parsed = JSON.parse(raw) as unknown;
        if (!isJsonObject(parsed)) {
          throw new InvalidJsonResponseError(response.status, raw);
        }

        return {
          status: response.status,
          body: parsed,
          raw,
        };
      } catch (error) {
        if (error instanceof InvalidJsonResponseError) {
          throw error;
        }
        throw new InvalidJsonResponseError(response.status, raw, { cause: error });
      }
    } catch (error) {
      if (error instanceof InvalidJsonResponseError) {
        throw error;
      }
      throw new TransportError('HTTP request failed.', { cause: error });
    } finally {
      clearTimeout(timeout);
    }
  }
}

function isJsonObject(value: unknown): value is JsonObject {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
