import type { JsonObject } from '../client/types.js';
import type { OutboxEnqueueResult, OutboxRecord, OutboxStats } from './types.js';

export interface OutboxStore {
  enqueue(eventKey: string, payload: JsonObject): Promise<OutboxEnqueueResult> | OutboxEnqueueResult;
  pullPending(limit?: number): Promise<OutboxRecord[]> | OutboxRecord[];
  markSent(id: string): Promise<void> | void;
  markRetrying(id: string, error: string, delayMs?: number): Promise<void> | void;
  markFailed(id: string, error: string): Promise<void> | void;
  isEventSent(eventKey: string): Promise<boolean> | boolean;
  getStats(): Promise<OutboxStats> | OutboxStats;
}
