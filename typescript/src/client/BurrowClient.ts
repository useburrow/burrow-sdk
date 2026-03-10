import type { HttpResponse, JsonObject } from './types.js';
import {
  toBackfillPayload,
  type BackfillEventsRequest,
  type BackfillEventsResponse,
  type BackfillRunOptions,
  type BackfillSummary,
  type BackfillValidationRejection,
} from './BackfillTypes.js';
import type { FormsContractSubmissionRequest } from '../contracts/FormsContractSubmissionRequest.js';
import { toFormsContractSubmissionPayload } from '../contracts/FormsContractSubmissionRequest.js';
import type { OnboardingDiscoveryRequest } from '../contracts/OnboardingDiscoveryRequest.js';
import { toOnboardingDiscoveryPayload } from '../contracts/OnboardingDiscoveryRequest.js';
import type { OnboardingLinkRequest } from '../contracts/OnboardingLinkRequest.js';
import { toOnboardingLinkPayload } from '../contracts/OnboardingLinkRequest.js';
import { HttpStatusError } from '../transport/errors.js';
import type { BurrowClientOptions } from './types.js';

export class BurrowClient {
  private readonly baseUrl: string;
  private readonly apiKey: string;
  private readonly transport: BurrowClientOptions['transport'];

  constructor(options: BurrowClientOptions) {
    this.baseUrl = options.baseUrl.replace(/\/+$/, '');
    this.apiKey = options.apiKey.trim();
    this.transport = options.transport;
  }

  async discover(request: OnboardingDiscoveryRequest): Promise<HttpResponse> {
    return this.post('/api/v1/plugin-onboarding/discover', toOnboardingDiscoveryPayload(request));
  }

  async link(request: OnboardingLinkRequest): Promise<HttpResponse> {
    return this.post('/api/v1/plugin-onboarding/link', toOnboardingLinkPayload(request));
  }

  async submitFormsContract(request: FormsContractSubmissionRequest): Promise<HttpResponse> {
    return this.post('/api/v1/plugin-onboarding/forms/contracts', toFormsContractSubmissionPayload(request));
  }

  async publishEvent(event: JsonObject): Promise<HttpResponse> {
    return this.post('/api/v1/events', event, [200, 207]);
  }

  async backfillEvents(request: BackfillEventsRequest, options: BackfillRunOptions = {}): Promise<BackfillEventsResponse> {
    const batchSize = Math.min(100, Math.max(1, options.batchSize ?? 100));
    const concurrency = Math.max(1, options.concurrency ?? 4);
    const sleepFn = options.sleepFn ?? sleep;
    const retry = {
      maxAttempts: options.retry?.maxAttempts ?? 3,
      baseDelayMs: options.retry?.baseDelayMs ?? 200,
      maxDelayMs: options.retry?.maxDelayMs ?? 2_000,
    };

    const accepted: JsonObject[] = [];
    const rejected: JsonObject[] = [];
    const validationRejections: BackfillValidationRejection[] = [];
    const validEvents: JsonObject[] = [];

    request.events.forEach((event, index) => {
      const normalized = normalizeBackfillEventTimestamp(event, index);
      if ('validationRejection' in normalized) {
        validationRejections.push(normalized.validationRejection);
        rejected.push({
          index: normalized.validationRejection.index,
          reason: normalized.validationRejection.reason,
          message: normalized.validationRejection.message,
        });
        return;
      }
      validEvents.push(normalized.event);
    });

    const chunks = chunk(validEvents, batchSize);
    let latestCursor = request.backfill.cursor;
    let completedCount = 0;
    let failedCount = 0;

    options.onProgress?.({
      status: 'queued',
      queuedCount: chunks.length,
      runningCount: 0,
      completedCount,
      failedCount,
      acceptedCount: 0,
      rejectedCount: rejected.length,
      latestCursor,
    });

    for (const windowChunks of chunk(chunks, concurrency)) {
      options.onProgress?.({
        status: 'running',
        queuedCount: chunks.length - completedCount - failedCount,
        runningCount: windowChunks.length,
        completedCount,
        failedCount,
        acceptedCount: accepted.length,
        rejectedCount: rejected.length,
        latestCursor,
      });

      try {
        const responses = await Promise.all(
          windowChunks.map((eventsChunk) =>
            this.submitBackfillChunkWithRetry(eventsChunk, request, retry.maxAttempts, retry.baseDelayMs, retry.maxDelayMs, sleepFn)
          )
        );

        for (const response of responses) {
          const body = response.body ?? {};
          const acceptedChunk = Array.isArray(body.accepted) ? body.accepted.filter(isJsonObject) : [];
          const rejectedChunk = Array.isArray(body.rejected) ? body.rejected.filter(isJsonObject) : [];
          accepted.push(...acceptedChunk);
          rejected.push(...rejectedChunk);

          if (isJsonObject(body.backfill) && typeof body.backfill.cursor === 'string') {
            latestCursor = body.backfill.cursor;
          }

          completedCount += 1;
        }
      } catch (error) {
        failedCount += 1;
        options.onProgress?.({
          status: 'failed',
          queuedCount: chunks.length - completedCount - failedCount,
          runningCount: 0,
          completedCount,
          failedCount,
          acceptedCount: accepted.length,
          rejectedCount: rejected.length,
          latestCursor,
        });
        throw error;
      }
    }

    const summary = extractBackfillSummary(
      request.events.length,
      accepted.length,
      rejected.length,
      validationRejections.length
    );
    const result: BackfillEventsResponse = {
      accepted,
      rejected,
      summary,
      validationRejections,
      latestCursor,
    };

    options.onProgress?.({
      status: 'completed',
      queuedCount: 0,
      runningCount: 0,
      completedCount,
      failedCount,
      acceptedCount: accepted.length,
      rejectedCount: rejected.length,
      latestCursor,
    });

    return result;
  }

  private async post(path: string, payload: JsonObject, acceptedStatuses?: readonly number[]): Promise<HttpResponse> {
    const url = `${this.baseUrl}${path}`;
    const response = await this.transport.post(
      url,
      {
        'x-api-key': this.apiKey,
      },
      payload
    );

    if (acceptedStatuses) {
      if (!acceptedStatuses.includes(response.status)) {
        throw new HttpStatusError(path, response.status, response.body, response.raw, response.headers ?? {});
      }
      return response;
    }

    if (response.status < 200 || response.status >= 300) {
      throw new HttpStatusError(path, response.status, response.body, response.raw, response.headers ?? {});
    }

    return response;
  }

  private async submitBackfillChunkWithRetry(
    eventsChunk: JsonObject[],
    request: BackfillEventsRequest,
    maxAttempts: number,
    baseDelayMs: number,
    maxDelayMs: number,
    sleepFn: (ms: number) => Promise<void>
  ): Promise<HttpResponse> {
    let attempt = 0;
    while (attempt < maxAttempts) {
      attempt += 1;

      try {
        return await this.post(
          '/api/v1/plugin-backfill/events',
          toBackfillPayload({
            events: eventsChunk,
            backfill: request.backfill,
          }) as unknown as JsonObject,
          [200, 207]
        );
      } catch (error) {
        const shouldRetry = isRetryableBackfillError(error);
        if (!shouldRetry || attempt >= maxAttempts) {
          throw error;
        }

        const delayMs = computeBackfillRetryDelayMs(error, attempt, baseDelayMs, maxDelayMs);
        if (delayMs > 0) {
          await sleepFn(delayMs);
        }
      }
    }

    throw new Error('Backfill retry attempts exhausted.');
  }
}

function isRetryableBackfillError(error: unknown): boolean {
  if (error instanceof HttpStatusError) {
    return error.status === 429 || error.status >= 500;
  }
  return error instanceof Error;
}

function computeBackfillRetryDelayMs(error: unknown, attempt: number, baseDelayMs: number, maxDelayMs: number): number {
  if (error instanceof HttpStatusError && error.status === 429) {
    const retryAfter = error.headers['retry-after'];
    if (retryAfter) {
      const numeric = Number(retryAfter);
      if (!Number.isNaN(numeric)) {
        return Math.max(0, Math.floor(numeric * 1000));
      }

      const retryAt = Date.parse(retryAfter);
      if (!Number.isNaN(retryAt)) {
        return Math.max(0, retryAt - Date.now());
      }
    }
  }

  const delay = Math.round(baseDelayMs * 2 ** (attempt - 1));
  return Math.min(delay, maxDelayMs);
}

function extractBackfillSummary(
  requestedCount: number,
  acceptedCount: number,
  rejectedCount: number,
  validationRejectedCount: number
): BackfillSummary {
  return {
    requestedCount,
    acceptedCount,
    rejectedCount,
    validationRejectedCount,
  };
}

function normalizeBackfillEventTimestamp(
  event: JsonObject,
  index: number
): { event: JsonObject } | { validationRejection: BackfillValidationRejection } {
  const timestamp = event.timestamp;
  if (typeof timestamp !== 'string' || timestamp.trim() === '') {
    return {
      validationRejection: {
        index,
        reason: 'missing_timestamp',
        message: 'Backfill event is missing required timestamp.',
      },
    };
  }

  const parsed = new Date(timestamp);
  if (Number.isNaN(parsed.getTime())) {
    return {
      validationRejection: {
        index,
        reason: 'invalid_timestamp',
        message: 'Backfill event timestamp is not a valid parseable date string.',
      },
    };
  }

  return {
    event: {
      ...event,
      timestamp: parsed.toISOString(),
    },
  };
}

function isJsonObject(value: unknown): value is JsonObject {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function chunk<T>(items: T[], size: number): T[][] {
  const output: T[][] = [];
  for (let index = 0; index < items.length; index += size) {
    output.push(items.slice(index, index + size));
  }
  return output;
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}
