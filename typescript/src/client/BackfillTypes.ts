import type { JsonObject } from './types.js';

export interface BackfillWindow {
  cursor?: string;
  windowStart: string;
  windowEnd?: string;
  source?: string;
}

export interface BackfillEventsRequest {
  events: JsonObject[];
  backfill: BackfillWindow;
}

export interface BackfillSummary {
  requestedCount: number;
  acceptedCount: number;
  rejectedCount: number;
}

export interface BackfillEventsResponse {
  accepted: JsonObject[];
  rejected: JsonObject[];
  summary: BackfillSummary;
  latestCursor?: string;
}

export type BackfillProgressStatus = 'queued' | 'running' | 'completed' | 'failed';

export interface BackfillProgressUpdate {
  status: BackfillProgressStatus;
  queuedCount: number;
  runningCount: number;
  completedCount: number;
  failedCount: number;
  acceptedCount: number;
  rejectedCount: number;
  latestCursor?: string;
}

export interface BackfillRetryOptions {
  maxAttempts?: number;
  baseDelayMs?: number;
  maxDelayMs?: number;
}

export interface BackfillRunOptions {
  batchSize?: number;
  concurrency?: number;
  retry?: BackfillRetryOptions;
  onProgress?: (update: BackfillProgressUpdate) => void;
  sleepFn?: (ms: number) => Promise<void>;
}

export interface BackfillPayload {
  events: JsonObject[];
  backfill: BackfillWindow;
}

export function toBackfillPayload(request: BackfillEventsRequest): BackfillPayload {
  return {
    events: request.events,
    backfill: {
      windowStart: request.backfill.windowStart,
      ...(request.backfill.cursor ? { cursor: request.backfill.cursor } : {}),
      ...(request.backfill.windowEnd ? { windowEnd: request.backfill.windowEnd } : {}),
      ...(request.backfill.source ? { source: request.backfill.source } : {}),
    },
  };
}
