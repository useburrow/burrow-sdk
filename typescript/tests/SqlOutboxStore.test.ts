import { describe, expect, it } from 'vitest';
import { SqlOutboxStore, type SqlOutboxAdapter } from '../src/outbox/SqlOutboxStore.js';
import type { OutboxStatus } from '../src/outbox/types.js';

interface Row {
  id: string;
  event_key: string;
  status: OutboxStatus;
  attempt_count: number;
  payload: string;
  last_error: string | null;
  created_at: string;
  updated_at: string;
  next_attempt_at: string | null;
  sent_at: string | null;
}

class FakeSqlAdapter implements SqlOutboxAdapter {
  public readonly rows = new Map<string, Row>();

  async execute(statement: string, params: readonly unknown[] = []): Promise<void> {
    if (statement.startsWith('INSERT INTO')) {
      const [id, eventKey, status, attemptCount, payload, lastError, createdAt, updatedAt, nextAttemptAt, sentAt] =
        params as [string, string, OutboxStatus, number, string, string | null, string, string, string | null, string | null];
      this.rows.set(id, {
        id,
        event_key: eventKey,
        status,
        attempt_count: attemptCount,
        payload,
        last_error: lastError,
        created_at: createdAt,
        updated_at: updatedAt,
        next_attempt_at: nextAttemptAt,
        sent_at: sentAt,
      });
      return;
    }

    if (statement.includes('SET status = ?')) {
      const status = params[0] as OutboxStatus;
      const id = params[params.length - 1] as string;
      const row = this.rows.get(id);
      if (!row) {
        return;
      }

      row.status = status;
      row.attempt_count += 1;
      row.updated_at = String(params[status === 'retrying' ? 2 : status === 'failed' ? 2 : 1]);

      if (status === 'sent') {
        row.last_error = null;
        row.next_attempt_at = null;
        row.sent_at = String(params[2]);
      } else if (status === 'retrying') {
        row.last_error = String(params[1]);
        row.next_attempt_at = (params[3] as string | null) ?? null;
        row.sent_at = null;
      } else if (status === 'failed') {
        row.last_error = String(params[1]);
        row.next_attempt_at = null;
      }
    }
  }

  async query<T>(statement: string, params: readonly unknown[] = []): Promise<T[]> {
    if (!statement.startsWith('SELECT id, event_key')) {
      return [];
    }

    const [pendingStatus, retryingStatus, now, limit] = params as [OutboxStatus, OutboxStatus, string, number];
    const all = [...this.rows.values()]
      .filter((row) => {
        if (row.status === pendingStatus) {
          return true;
        }
        if (row.status === retryingStatus) {
          return row.next_attempt_at === null || row.next_attempt_at <= now;
        }
        return false;
      })
      .sort((a, b) => a.created_at.localeCompare(b.created_at))
      .slice(0, limit);

    return all as T[];
  }
}

describe('SqlOutboxStore', () => {
  it('enqueues and pulls pending rows', async () => {
    const adapter = new FakeSqlAdapter();
    const store = new SqlOutboxStore(adapter, { now: () => new Date('2026-03-09T00:00:00.000Z') });

    const record = await store.enqueue('forms:contact:sub_123', { event: 'forms.submission.received' });
    const rows = await store.pullPending(10);

    expect(rows).toHaveLength(1);
    expect(rows[0]?.id).toBe(record.id);
    expect(rows[0]?.status).toBe('pending');
    expect(rows[0]?.eventKey).toBe('forms:contact:sub_123');
  });

  it('applies status transitions and keeps stable event key', async () => {
    const adapter = new FakeSqlAdapter();
    let now = new Date('2026-03-09T00:00:00.000Z');
    const store = new SqlOutboxStore(adapter, { now: () => now });

    const record = await store.enqueue('forms:contact:sub_123', { event: 'forms.submission.received' });

    now = new Date('2026-03-09T00:00:05.000Z');
    await store.markRetrying(record.id, 'temporary failure', 1000);
    let row = adapter.rows.get(record.id);
    expect(row?.status).toBe('retrying');
    expect(row?.attempt_count).toBe(1);
    expect(row?.event_key).toBe('forms:contact:sub_123');

    now = new Date('2026-03-09T00:00:10.000Z');
    await store.markFailed(record.id, 'permanent failure');
    row = adapter.rows.get(record.id);
    expect(row?.status).toBe('failed');
    expect(row?.attempt_count).toBe(2);

    now = new Date('2026-03-09T00:00:15.000Z');
    await store.markSent(record.id);
    row = adapter.rows.get(record.id);
    expect(row?.status).toBe('sent');
    expect(row?.attempt_count).toBe(3);
    expect(row?.sent_at).toBe('2026-03-09T00:00:15.000Z');
  });
});
