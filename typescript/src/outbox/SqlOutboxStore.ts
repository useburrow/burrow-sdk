import { randomUUID } from 'node:crypto';
import type { JsonObject } from '../client/types.js';
import type { OutboxStore } from './OutboxStore.js';
import type { OutboxRecord, OutboxStatus } from './types.js';

export interface SqlOutboxAdapter {
  execute(statement: string, params?: readonly unknown[]): Promise<void>;
  query<T>(statement: string, params?: readonly unknown[]): Promise<T[]>;
}

export interface SqlOutboxStoreOptions {
  tableName?: string;
  now?: () => Date;
}

interface SqlOutboxRow {
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

export class SqlOutboxStore implements OutboxStore {
  private readonly tableName: string;
  private readonly now: () => Date;

  constructor(
    private readonly adapter: SqlOutboxAdapter,
    options: SqlOutboxStoreOptions = {}
  ) {
    this.tableName = options.tableName ?? 'burrow_outbox';
    this.now = options.now ?? (() => new Date());
  }

  async enqueue(eventKey: string, payload: JsonObject): Promise<OutboxRecord> {
    const id = randomUUID();
    const now = this.now().toISOString();

    await this.adapter.execute(
      `INSERT INTO ${this.tableName}
        (id, event_key, status, attempt_count, payload, last_error, created_at, updated_at, next_attempt_at, sent_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [id, eventKey, 'pending', 0, JSON.stringify(payload), null, now, now, null, null]
    );

    return {
      id,
      eventKey,
      status: 'pending',
      attemptCount: 0,
      payload,
      lastError: null,
      createdAt: new Date(now),
      updatedAt: new Date(now),
      nextAttemptAt: null,
      sentAt: null,
    };
  }

  async pullPending(limit = 50): Promise<OutboxRecord[]> {
    const now = this.now().toISOString();
    const rows = await this.adapter.query<SqlOutboxRow>(
      `SELECT id, event_key, status, attempt_count, payload, last_error, created_at, updated_at, next_attempt_at, sent_at
         FROM ${this.tableName}
        WHERE status = ?
           OR (status = ? AND (next_attempt_at IS NULL OR next_attempt_at <= ?))
        ORDER BY created_at ASC
        LIMIT ?`,
      ['pending', 'retrying', now, limit]
    );

    return rows.map((row) => this.toRecord(row));
  }

  async markSent(id: string): Promise<void> {
    const now = this.now().toISOString();
    await this.adapter.execute(
      `UPDATE ${this.tableName}
          SET status = ?,
              attempt_count = attempt_count + 1,
              last_error = NULL,
              updated_at = ?,
              next_attempt_at = NULL,
              sent_at = ?
        WHERE id = ?`,
      ['sent', now, now, id]
    );
  }

  async markRetrying(id: string, error: string, delayMs = 0): Promise<void> {
    const now = this.now();
    const nextAttemptAt = delayMs > 0 ? new Date(now.getTime() + delayMs).toISOString() : null;

    await this.adapter.execute(
      `UPDATE ${this.tableName}
          SET status = ?,
              attempt_count = attempt_count + 1,
              last_error = ?,
              updated_at = ?,
              next_attempt_at = ?,
              sent_at = NULL
        WHERE id = ?`,
      ['retrying', error, now.toISOString(), nextAttemptAt, id]
    );
  }

  async markFailed(id: string, error: string): Promise<void> {
    const now = this.now().toISOString();
    await this.adapter.execute(
      `UPDATE ${this.tableName}
          SET status = ?,
              attempt_count = attempt_count + 1,
              last_error = ?,
              updated_at = ?,
              next_attempt_at = NULL
        WHERE id = ?`,
      ['failed', error, now, id]
    );
  }

  private toRecord(row: SqlOutboxRow): OutboxRecord {
    const payload = JSON.parse(row.payload) as unknown;
    if (!isJsonObject(payload)) {
      throw new Error('Outbox payload JSON must decode into an object.');
    }

    return {
      id: row.id,
      eventKey: row.event_key,
      status: row.status,
      attemptCount: row.attempt_count,
      payload,
      lastError: row.last_error,
      createdAt: new Date(row.created_at),
      updatedAt: new Date(row.updated_at),
      nextAttemptAt: row.next_attempt_at ? new Date(row.next_attempt_at) : null,
      sentAt: row.sent_at ? new Date(row.sent_at) : null,
    };
  }
}

function isJsonObject(value: unknown): value is JsonObject {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
