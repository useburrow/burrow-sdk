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
import {
  parseFormsContractsResponse,
  toFormsContractsFetchPayload,
  type FormsContractsResponse,
} from '../contracts/FormsContracts.js';
import type { OnboardingDiscoveryRequest } from '../contracts/OnboardingDiscoveryRequest.js';
import { toOnboardingDiscoveryPayload } from '../contracts/OnboardingDiscoveryRequest.js';
import type { OnboardingLinkRequest } from '../contracts/OnboardingLinkRequest.js';
import { toOnboardingLinkPayload } from '../contracts/OnboardingLinkRequest.js';
import {
  isProjectScopedIngestionKey,
  parseOnboardingLinkResponse,
  toLinkedProjectDeepLink,
  type LinkedProjectDeepLink,
  type OnboardingLinkResponse,
} from '../contracts/OnboardingLinkResponse.js';
import { isRetryableSdkError, normalizeApiError, SdkPreflightError } from '../transport/errors.js';
import type { BurrowClientOptions } from './types.js';

export interface BurrowClientState {
  ingestionKey: string | null;
  projectId: string | null;
  projectSourceIds: {
    forms: string | null;
  };
  contractsVersion: string | null;
  contractMappings: JsonObject[];
  clientId: string | null;
}

export interface BackfillRouting {
  projectId: string;
  projectSourceId: string;
  clientId?: string;
}

export interface BurrowDebugLogEntry {
  endpoint: string;
  status: number;
  errorCode: string;
  rejectedReasons: string[];
  apiKeyPrefix: string;
}

export class BurrowClient {
  private readonly baseUrl: string;
  private apiKey: string;
  private readonly transport: BurrowClientOptions['transport'];
  private readonly debugLogger?: BurrowClientOptions['debugLogger'];
  private scopedProjectId: string | null = null;
  private lastLinkResponse: OnboardingLinkResponse | null = null;
  private state: BurrowClientState;

  constructor(options: BurrowClientOptions) {
    this.baseUrl = options.baseUrl.replace(/\/+$/, '');
    this.apiKey = options.apiKey.trim();
    this.transport = options.transport;
    this.debugLogger = options.debugLogger;
    const initialState = options.state ?? {};
    this.state = {
      ingestionKey: initialState.ingestionKey ?? null,
      projectId: initialState.projectId ?? null,
      projectSourceIds: { forms: initialState.projectSourceIds?.forms ?? null },
      contractsVersion: initialState.contractsVersion ?? null,
      contractMappings: Array.isArray(initialState.contractMappings) ? initialState.contractMappings : [],
      clientId: initialState.clientId ?? null,
    };
  }

  async discover(request: OnboardingDiscoveryRequest): Promise<HttpResponse> {
    return this.post('/api/v1/plugin-onboarding/discover', toOnboardingDiscoveryPayload(request));
  }

  async link(request: OnboardingLinkRequest): Promise<OnboardingLinkResponse> {
    const response = await this.post('/api/v1/plugin-onboarding/link', toOnboardingLinkPayload(request));
    const parsed = parseOnboardingLinkResponse(response.body);
    this.lastLinkResponse = parsed;

    if (parsed.ingestionKey?.key) {
      this.apiKey = parsed.ingestionKey.key;
      this.state.ingestionKey = parsed.ingestionKey.key;
    }

    const linkedProjectId =
      parsed.project?.id ??
      parsed.ingestionKey?.projectId ??
      (typeof parsed.routing.projectId === 'string' ? parsed.routing.projectId : null);

    if (linkedProjectId && linkedProjectId.trim() !== '') {
      this.state.projectId = linkedProjectId.trim();
    }

    this.state.clientId =
      parsed.project?.clientId ??
      (typeof parsed.routing.clientId === 'string' ? parsed.routing.clientId : this.state.clientId);

    this.scopedProjectId = isProjectScopedIngestionKey(parsed.ingestionKey) ? parsed.ingestionKey.projectId : null;

    return parsed;
  }

  async submitFormsContract(request: FormsContractSubmissionRequest): Promise<FormsContractsResponse> {
    const payload = toFormsContractSubmissionPayload(request);
    this.assertScopedProjectAllowedForFormsPayload(payload);
    const response = await this.post('/api/v1/plugin-onboarding/forms/contracts', payload);
    const parsed = parseFormsContractsResponse(response.body);
    if (parsed.projectSourceId) {
      this.state.projectSourceIds.forms = parsed.projectSourceId;
    }
    if (parsed.contractsVersion) {
      this.state.contractsVersion = parsed.contractsVersion;
    }
    this.state.contractMappings = parsed.contractMappings.map((mapping) => ({ ...mapping }));
    return parsed;
  }

  async fetchFormsContracts(projectId: string, platform: string): Promise<FormsContractsResponse> {
    this.assertScopedProjectIdMatches(projectId, 'forms contracts fetch');
    const response = await this.post('/api/v1/plugin-onboarding/forms/contracts/fetch', toFormsContractsFetchPayload(projectId, platform));
    return parseFormsContractsResponse(response.body);
  }

  getLinkedProjectDeepLink(): LinkedProjectDeepLink | null {
    if (!this.lastLinkResponse) {
      return null;
    }

    return toLinkedProjectDeepLink(this.lastLinkResponse);
  }

  getState(): BurrowClientState {
    return {
      ...this.state,
      projectSourceIds: { ...this.state.projectSourceIds },
      contractMappings: [...this.state.contractMappings],
    };
  }

  getProjectId(): string | null {
    return this.state.projectId;
  }

  getProjectSourceId(channel: 'forms' = 'forms'): string | null {
    if (channel !== 'forms') {
      return null;
    }
    return this.state.projectSourceIds.forms;
  }

  getBackfillRouting(channel: 'forms'): BackfillRouting {
    if (!this.state.projectId) {
      throw new SdkPreflightError(
        'MISSING_PROJECT_ID',
        'Cannot run forms backfill without a projectId.',
        'Run plugin onboarding link first so project context can be stored.'
      );
    }
    if (!this.state.projectSourceIds.forms) {
      throw new SdkPreflightError(
        'MISSING_PROJECT_SOURCE_ID',
        'Cannot run forms backfill without a projectSourceId.',
        'Sync forms contracts first so projectSourceId is persisted in SDK state.'
      );
    }

    return {
      projectId: this.state.projectId,
      projectSourceId: this.state.projectSourceIds.forms,
      ...(this.state.clientId ? { clientId: this.state.clientId } : {}),
    };
  }

  async publishEvent(event: JsonObject): Promise<HttpResponse> {
    this.assertScopedProjectAllowedForEvent(event);
    return this.post('/api/v1/events', event, [200, 207]);
  }

  async backfillEvents(request: BackfillEventsRequest, options: BackfillRunOptions = {}): Promise<BackfillEventsResponse> {
    this.assertBackfillPreflight(request);
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
      const normalized = normalizeBackfillEvent(request, event, index);
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
        const normalized = normalizeApiError(path, response.status, response.body, response.raw, response.headers ?? {});
        this.logApiError({
          endpoint: path,
          status: response.status,
          errorCode: normalized.code,
          rejectedReasons: normalized.rejected
            .map((row) => (typeof row.reason === 'string' ? row.reason : null))
            .filter((value): value is string => value !== null),
          apiKeyPrefix: redactApiKey(this.apiKey),
        });
        throw normalized;
      }
      return response;
    }

    if (response.status < 200 || response.status >= 300) {
      const normalized = normalizeApiError(path, response.status, response.body, response.raw, response.headers ?? {});
      this.logApiError({
        endpoint: path,
        status: response.status,
        errorCode: normalized.code,
        rejectedReasons: normalized.rejected
          .map((row) => (typeof row.reason === 'string' ? row.reason : null))
          .filter((value): value is string => value !== null),
        apiKeyPrefix: redactApiKey(this.apiKey),
      });
      throw normalized;
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
            routing:
              request.channel === 'forms'
                ? this.getBackfillRouting('forms')
                : request.routing?.projectId
                  ? {
                      projectId: request.routing.projectId,
                      ...(request.routing.projectSourceId ? { projectSourceId: request.routing.projectSourceId } : {}),
                      ...(request.routing.clientId ? { clientId: request.routing.clientId } : {}),
                    }
                  : undefined,
          }) as unknown as JsonObject,
          [200, 207]
        );
      } catch (error) {
        const shouldRetry = isRetryableSdkError(error);
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

  private assertScopedProjectAllowedForEvent(event: JsonObject): void {
    if (!this.scopedProjectId) {
      return;
    }

    const projectId = typeof event.projectId === 'string' ? event.projectId.trim() : '';
    if (!projectId) {
      throw new Error('projectId is required when using a project-scoped ingestion key.');
    }

    if (projectId !== this.scopedProjectId) {
      throw new Error(`projectId "${projectId}" does not match scoped key project "${this.scopedProjectId}".`);
    }
  }

  private assertScopedProjectAllowedForFormsPayload(payload: JsonObject): void {
    if (!this.scopedProjectId) {
      return;
    }

    const routing = isJsonObject(payload.routing) ? payload.routing : null;
    const projectId = routing && typeof routing.projectId === 'string' ? routing.projectId.trim() : '';
    if (!projectId) {
      throw new Error('routing.projectId is required when using a project-scoped ingestion key.');
    }

    this.assertScopedProjectIdMatches(projectId, 'forms contracts');
  }

  private assertScopedProjectIdMatches(projectId: string, operation: string): void {
    if (!this.scopedProjectId) {
      return;
    }

    if (projectId !== this.scopedProjectId) {
      throw new Error(
        `Cannot ${operation} for project "${projectId}" with scoped key for project "${this.scopedProjectId}".`
      );
    }
  }

  private assertBackfillPreflight(request: BackfillEventsRequest): void {
    if (request.channel !== 'forms') {
      return;
    }

    if (!this.state.ingestionKey) {
      throw new SdkPreflightError(
        'MISSING_INGESTION_KEY',
        'Cannot run forms backfill without an ingestion key.',
        'Run onboarding link and persist the returned ingestionKey before backfill.'
      );
    }

    this.getBackfillRouting('forms');
  }

  private logApiError(entry: BurrowDebugLogEntry): void {
    if (!this.debugLogger) {
      return;
    }
    this.debugLogger(entry);
  }
}

function computeBackfillRetryDelayMs(error: unknown, attempt: number, baseDelayMs: number, maxDelayMs: number): number {
  if (isRetryableSdkError(error) && typeof (error as { status?: number }).status === 'number' && (error as { status: number }).status === 429) {
    const headers = (error as { headers?: Record<string, string> }).headers ?? {};
    const retryAfter = headers['retry-after'];
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

function normalizeBackfillEvent(
  request: BackfillEventsRequest,
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
      channel: typeof event.channel === 'string' && event.channel.trim() !== '' ? event.channel : request.channel ?? 'forms',
      ...(typeof event.event === 'string' && event.event.trim() !== ''
        ? {}
        : request.channel === 'forms' || request.channel === undefined
          ? { event: 'forms.submission.received' }
          : {}),
      ...(typeof event.source === 'string' && event.source.trim() !== ''
        ? {}
        : request.source
          ? { source: request.source }
          : {}),
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

function redactApiKey(apiKey: string): string {
  const trimmed = apiKey.trim();
  if (trimmed.length <= 8) {
    return `${trimmed}***`;
  }
  return `${trimmed.slice(0, 8)}***`;
}
