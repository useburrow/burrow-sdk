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
}

export function createExponentialBackoffStrategy(
  options: ExponentialBackoffOptions = {}
): BackoffStrategy {
  const baseDelayMs = options.baseDelayMs ?? 2_000;
  const multiplier = options.multiplier ?? 2;
  const maxDelayMs = options.maxDelayMs ?? 300_000;

  return {
    delayMsForAttempt(attemptNumber: number): number {
      if (attemptNumber <= 0) {
        return 0;
      }

      const delay = Math.round(baseDelayMs * multiplier ** (attemptNumber - 1));
      return Math.min(delay, maxDelayMs);
    },
  };
}
