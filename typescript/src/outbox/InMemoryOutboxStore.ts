import { randomUUID } from 'node:crypto';
import type { JsonObject } from '../client/types.js';
import type { OutboxStore } from './OutboxStore.js';
import type { OutboxEnqueueResult, OutboxRecord, OutboxStats } from './types.js';

export class InMemoryOutboxStore implements OutboxStore {
  private readonly records = new Map<string, OutboxRecord>();
  private readonly eventKeyToId = new Map<string, string>();
  private readonly sentLedger = new Map<string, Date>();

  enqueue(eventKey: string, payload: JsonObject): OutboxEnqueueResult {
    if (this.sentLedger.has(eventKey) || this.eventKeyToId.has(eventKey)) {
      return { deduped: true, eventKey };
    }

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
    this.eventKeyToId.set(eventKey, id);
    return { deduped: false, eventKey, record: { ...record } };
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

  isEventSent(eventKey: string): boolean {
    return this.sentLedger.has(eventKey);
  }

  getStats(): OutboxStats {
    let pending = 0;
    let retrying = 0;
    let sent = 0;
    let failed = 0;
    for (const record of this.records.values()) {
      if (record.status === 'pending') pending += 1;
      if (record.status === 'retrying') retrying += 1;
      if (record.status === 'sent') sent += 1;
      if (record.status === 'failed') failed += 1;
    }
    return {
      pending,
      retrying,
      sent,
      failed,
      sentLedgerCount: this.sentLedger.size,
    };
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
    if (markAsSent) {
      this.sentLedger.set(current.eventKey, now);
    }
  }
}
