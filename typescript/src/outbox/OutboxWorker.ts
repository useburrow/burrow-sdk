import type { BurrowClient } from '../client/BurrowClient.js';
import type { OutboxStore } from './OutboxStore.js';
import {
  createExponentialBackoffStrategy,
  type BackoffStrategy,
  type OutboxRecord,
  type OutboxWorkerResult,
} from './types.js';
import { HttpStatusError, TransportError } from '../transport/errors.js';

export interface OutboxLogEntry {
  eventKey: string;
  eventKeyShort: string;
  fromStatus: OutboxRecord['status'];
  toStatus: OutboxRecord['status'];
  attemptCount: number;
  message?: string;
  httpStatus?: number;
  errorCode?: string;
  retryable?: boolean;
}

export interface OutboxWorkerOptions {
  maxAttempts?: number;
  batchSize?: number;
  backoffStrategy?: BackoffStrategy;
  logger?: (entry: OutboxLogEntry) => void;
  skipNetworkSend?: boolean;
}

export class OutboxWorker {
  private readonly maxAttempts: number;
  private readonly batchSize: number;
  private readonly backoffStrategy: BackoffStrategy;
  private readonly logger?: (entry: OutboxLogEntry) => void;
  private readonly skipNetworkSend: boolean;

  constructor(
    private readonly store: OutboxStore,
    private readonly client: BurrowClient,
    options: OutboxWorkerOptions = {}
  ) {
    this.maxAttempts = options.maxAttempts ?? 5;
    this.batchSize = options.batchSize ?? 50;
    this.backoffStrategy = options.backoffStrategy ?? createExponentialBackoffStrategy();
    this.logger = options.logger;
    this.skipNetworkSend = options.skipNetworkSend ?? false;
  }

  async runOnce(limit = this.batchSize): Promise<OutboxWorkerResult> {
    const records = await this.store.pullPending(limit);
    const result: OutboxWorkerResult = {
      processedCount: records.length,
      sentCount: 0,
      retryingCount: 0,
      failedCount: 0,
    };

    for (const record of records) {
      if (this.skipNetworkSend) {
        await this.store.markSent(record.id);
        this.logger?.({
          eventKey: record.eventKey,
          eventKeyShort: shortenEventKey(record.eventKey),
          fromStatus: record.status,
          toStatus: 'sent',
          attemptCount: record.attemptCount + 1,
          message: 'Skipped network publish (skipNetworkSend enabled).',
          retryable: false,
        });
        result.sentCount += 1;
        continue;
      }

      try {
        const response = await this.client.publishEvent(record.payload);
        if (response.status === 200 || response.status === 207) {
          await this.store.markSent(record.id);
          this.logger?.({
            eventKey: record.eventKey,
            eventKeyShort: shortenEventKey(record.eventKey),
            fromStatus: record.status,
            toStatus: 'sent',
            attemptCount: record.attemptCount + 1,
          });
          result.sentCount += 1;
          continue;
        }

        throw new HttpStatusError('/api/v1/events', response.status, response.body, response.raw);
      } catch (error) {
        if (this.shouldRetry(record, error)) {
          const nextAttempt = record.attemptCount + 1;
          const delayMs = this.backoffStrategy.delayMsForAttempt(nextAttempt);
          await this.store.markRetrying(record.id, toErrorMessage(error), delayMs);
          this.logger?.({
            eventKey: record.eventKey,
            eventKeyShort: shortenEventKey(record.eventKey),
            fromStatus: record.status,
            toStatus: 'retrying',
            attemptCount: nextAttempt,
            message: toErrorMessage(error),
            httpStatus: error instanceof HttpStatusError ? error.status : undefined,
            errorCode: readErrorCode(error),
            retryable: true,
          });
          result.retryingCount += 1;
          continue;
        }

        await this.store.markFailed(record.id, toErrorMessage(error));
        this.logger?.({
          eventKey: record.eventKey,
          eventKeyShort: shortenEventKey(record.eventKey),
          fromStatus: record.status,
          toStatus: 'failed',
          attemptCount: record.attemptCount + 1,
          message: toErrorMessage(error),
          httpStatus: error instanceof HttpStatusError ? error.status : undefined,
          errorCode: readErrorCode(error),
          retryable: false,
        });
        result.failedCount += 1;
      }
    }

    return result;
  }

  private shouldRetry(record: OutboxRecord, error: unknown): boolean {
    const nextAttempt = record.attemptCount + 1;
    if (nextAttempt >= this.maxAttempts) {
      return false;
    }

    if (error instanceof TransportError) {
      return true;
    }

    if (error instanceof HttpStatusError) {
      return error.retryable;
    }

    return false;
  }
}

function toErrorMessage(error: unknown): string {
  if (error instanceof Error) {
    return error.message;
  }

  return String(error);
}

function shortenEventKey(eventKey: string): string {
  return eventKey.slice(0, 12);
}

function readErrorCode(error: unknown): string | undefined {
  if (typeof error === 'object' && error !== null && 'code' in error && typeof (error as { code: unknown }).code === 'string') {
    return (error as { code: string }).code;
  }
  return undefined;
}
