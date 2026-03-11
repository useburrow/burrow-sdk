import type { JsonObject } from '../client/types.js';

export type OutboxStatus = 'pending' | 'retrying' | 'sent' | 'failed';

export interface OutboxRecord {
  id: string;
  eventKey: string;
  status: OutboxStatus;
  attemptCount: number;
  payload: JsonObject;
  lastError: string | null;
  createdAt: Date;
  updatedAt: Date;
  nextAttemptAt: Date | null;
  sentAt: Date | null;
}

export interface OutboxEnqueueResult {
  deduped: boolean;
  eventKey: string;
  record?: OutboxRecord;
}

export interface OutboxStats {
  pending: number;
  retrying: number;
  sent: number;
  failed: number;
  sentLedgerCount: number;
}

export interface OutboxWorkerResult {
  processedCount: number;
  sentCount: number;
  retryingCount: number;
  failedCount: number;
}

export interface BackoffStrategy {
  delayMsForAttempt(attemptNumber: number): number;
}

export interface ExponentialBackoffOptions {
  baseDelayMs?: number;
  multiplier?: number;
  maxDelayMs?: number;
  jitterRatio?: number;
  random?: () => number;
}

export function createExponentialBackoffStrategy(
  options: ExponentialBackoffOptions = {}
): BackoffStrategy {
  const baseDelayMs = options.baseDelayMs ?? 2_000;
  const multiplier = options.multiplier ?? 2;
  const maxDelayMs = options.maxDelayMs ?? 300_000;
  const jitterRatio = Math.min(1, Math.max(0, options.jitterRatio ?? 0.2));
  const random = options.random ?? Math.random;

  return {
    delayMsForAttempt(attemptNumber: number): number {
      if (attemptNumber <= 0) {
        return 0;
      }

      const delay = Math.min(Math.round(baseDelayMs * multiplier ** (attemptNumber - 1)), maxDelayMs);
      const jitterWindow = Math.round(delay * jitterRatio);
      if (jitterWindow <= 0) {
        return delay;
      }

      const jitter = Math.round((random() * 2 - 1) * jitterWindow);
      return Math.max(0, delay + jitter);
    },
  };
}
