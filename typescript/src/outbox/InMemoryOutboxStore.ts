import { randomUUID } from 'node:crypto';
import type { JsonObject } from '../client/types.js';
import type { OutboxStore } from './OutboxStore.js';
import type { OutboxRecord } from './types.js';

export class InMemoryOutboxStore implements OutboxStore {
  private readonly records = new Map<string, OutboxRecord>();

  enqueue(eventKey: string, payload: JsonObject): OutboxRecord {
    const now = new Date();
    const id = randomUUID();
    const record: OutboxRecord = {
      id,
      eventKey,
      status: 'pending',
      attemptCount: 0,
      payload,
      lastError: null,
      createdAt: now,
      updatedAt: now,
      nextAttemptAt: null,
      sentAt: null,
    };

    this.records.set(id, record);
    return { ...record };
  }

  pullPending(limit = 50): OutboxRecord[] {
    const now = Date.now();
    const pending = [...this.records.values()]
      .filter((record) => {
        if (record.status === 'pending') {
          return true;
        }

        if (record.status === 'retrying') {
          return record.nextAttemptAt === null || record.nextAttemptAt.getTime() <= now;
        }

        return false;
      })
      .sort((a, b) => a.createdAt.getTime() - b.createdAt.getTime())
      .slice(0, limit);

    return pending.map((record) => ({ ...record }));
  }

  markSent(id: string): void {
    this.updateStatus(id, 'sent', null, null, true);
  }

  markRetrying(id: string, error: string, delayMs = 0): void {
    const nextAttemptAt = delayMs > 0 ? new Date(Date.now() + delayMs) : null;
    this.updateStatus(id, 'retrying', error, nextAttemptAt, false);
  }

  markFailed(id: string, error: string): void {
    this.updateStatus(id, 'failed', error, null, false);
  }

  getById(id: string): OutboxRecord | undefined {
    const record = this.records.get(id);
    return record ? { ...record } : undefined;
  }

  private updateStatus(
    id: string,
    status: OutboxRecord['status'],
    lastError: string | null,
    nextAttemptAt: Date | null,
    markAsSent: boolean
  ): void {
    const current = this.records.get(id);
    if (!current) {
      return;
    }

    const now = new Date();
    this.records.set(id, {
      ...current,
      status,
      attemptCount: current.attemptCount + 1,
      lastError,
      updatedAt: now,
      nextAttemptAt,
      sentAt: markAsSent ? now : null,
    });
  }
}
