import type { HttpResponse, JsonObject } from '../client/types.js';
import type { HttpTransport } from './HttpTransport.js';
import { InvalidJsonResponseError, TransportError } from './errors.js';
import { createDefaultRetryPolicy, type RetryPolicy, type RetryPolicyOptions } from './RetryPolicy.js';

export interface FetchTransportOptions {
  timeoutMs?: number;
  fetchImpl?: typeof fetch;
  retryPolicy?: RetryPolicy | RetryPolicyOptions;
  sleepFn?: (ms: number) => Promise<void>;
}

export class FetchTransport implements HttpTransport {
  private readonly timeoutMs: number;
  private readonly fetchImpl: typeof fetch;
  private readonly retryPolicy: RetryPolicy;
  private readonly sleepFn: (ms: number) => Promise<void>;

  constructor(options: FetchTransportOptions = {}) {
    this.timeoutMs = options.timeoutMs ?? 5_000;
    this.fetchImpl = options.fetchImpl ?? globalThis.fetch;
    this.retryPolicy = isRetryPolicy(options.retryPolicy)
      ? options.retryPolicy
      : createDefaultRetryPolicy(options.retryPolicy);
    this.sleepFn = options.sleepFn ?? sleep;
  }

  async post(url: string, headers: Record<string, string>, payload: JsonObject): Promise<HttpResponse> {
    for (let attempt = 1; attempt <= this.retryPolicy.maxAttempts; attempt += 1) {
      try {
        const response = await this.postOnce(url, headers, payload);
        if (this.retryPolicy.shouldRetryStatus(response.status, attempt)) {
          await this.sleepForRetry(attempt);
          continue;
        }
        return response;
      } catch (error) {
        if (error instanceof TransportError && this.retryPolicy.shouldRetryTransportFailure(attempt)) {
          await this.sleepForRetry(attempt);
          continue;
        }
        throw error;
      }
    }

    throw new TransportError('HTTP request failed after exhausting retry attempts.');
  }

  private async postOnce(url: string, headers: Record<string, string>, payload: JsonObject): Promise<HttpResponse> {
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
      const responseHeaders = toHeaderRecord(response.headers);
      if (raw === '') {
        return { status: response.status, body: null, raw, headers: responseHeaders };
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
          headers: responseHeaders,
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

  private async sleepForRetry(attempt: number): Promise<void> {
    const delayMs = this.retryPolicy.delayMsForAttempt(attempt);
    if (delayMs <= 0) {
      return;
    }

    await this.sleepFn(delayMs);
  }
}

function isJsonObject(value: unknown): value is JsonObject {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isRetryPolicy(value: RetryPolicy | RetryPolicyOptions | undefined): value is RetryPolicy {
  if (!value) {
    return false;
  }

  const candidate = value as Partial<RetryPolicy>;
  return (
    typeof candidate.maxAttempts === 'number' &&
    typeof candidate.shouldRetryStatus === 'function' &&
    typeof candidate.shouldRetryTransportFailure === 'function' &&
    typeof candidate.delayMsForAttempt === 'function'
  );
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

function toHeaderRecord(headers: Headers): Record<string, string> {
  const output: Record<string, string> = {};
  headers.forEach((value, key) => {
    output[key.toLowerCase()] = value;
  });
  return output;
}
