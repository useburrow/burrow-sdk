import type { JsonObject } from '../client/types.js';
import type { BurrowClient } from '../client/BurrowClient.js';
import type { OutboxStore } from './OutboxStore.js';
import { buildDeterministicEventKey, type EventKeyContext } from './EventKey.js';
import { OutboxWorker, type OutboxLogEntry, type OutboxWorkerOptions } from './OutboxWorker.js';
import type { OutboxStats, OutboxWorkerResult } from './types.js';

export interface EnqueueEventsContext extends EventKeyContext {
  logger?: (entry: OutboxLogEntry | Record<string, unknown>) => void;
}

export interface EnqueueEventsItemResult {
  eventKey: string;
  deduped: boolean;
}

export interface EnqueueEventsResult {
  enqueued: number;
  deduped: number;
  items: EnqueueEventsItemResult[];
}

export interface FlushOutboxResult extends OutboxWorkerResult {
  retried: number;
}

export interface BackfillBatchResult {
  enqueued: number;
  deduped: number;
  sent: number;
  retried: number;
  failed: number;
  checkpointAdvanceSafe: boolean;
}

export class OutboxDelivery {
  private readonly worker: OutboxWorker;

  constructor(
    private readonly store: OutboxStore,
    client: BurrowClient,
    workerOptions: OutboxWorkerOptions = {}
  ) {
    this.worker = new OutboxWorker(store, client, workerOptions);
  }

  async enqueueEvents(events: JsonObject[], context: EnqueueEventsContext = {}): Promise<EnqueueEventsResult> {
    const items: EnqueueEventsItemResult[] = [];
    let enqueued = 0;
    let deduped = 0;

    for (const event of events) {
      const eventKey = buildDeterministicEventKey(event, context).eventKey;
      const alreadySent = await this.store.isEventSent(eventKey);
      if (alreadySent) {
        deduped += 1;
        items.push({ eventKey, deduped: true });
        continue;
      }

      const result = await this.store.enqueue(eventKey, event);
      if (result.deduped) {
        deduped += 1;
      } else {
        enqueued += 1;
      }
      items.push({ eventKey, deduped: result.deduped });
    }

    return { enqueued, deduped, items };
  }

  async flushOutbox(limit?: number): Promise<FlushOutboxResult> {
    const result = await this.worker.runOnce(limit);
    return {
      ...result,
      retried: result.retryingCount,
    };
  }

  async getOutboxStats(): Promise<OutboxStats> {
    return this.store.getStats();
  }

  async runBackfillBatch(events: JsonObject[], context: EnqueueEventsContext = {}, flushLimit?: number): Promise<BackfillBatchResult> {
    const enqueue = await this.enqueueEvents(events, context);
    const flush = await this.flushOutbox(flushLimit);
    const stats = await this.getOutboxStats();
    return {
      enqueued: enqueue.enqueued,
      deduped: enqueue.deduped,
      sent: flush.sentCount,
      retried: flush.retryingCount,
      failed: flush.failedCount,
      checkpointAdvanceSafe: stats.pending === 0 && stats.retrying === 0,
    };
  }
}
