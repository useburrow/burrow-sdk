import { describe, expect, it } from 'vitest';
import { InMemoryOutboxStore } from '../src/outbox/InMemoryOutboxStore.js';

describe('InMemoryOutboxStore', () => {
  it('enqueues and pulls pending records', () => {
    const store = new InMemoryOutboxStore();
    const record = store.enqueue('forms:contact:sub_123', { event: 'forms.submission.received' });

    const rows = store.pullPending(10);
    expect(rows).toHaveLength(1);
    expect(rows[0]?.id).toBe(record.id);
    expect(rows[0]?.eventKey).toBe('forms:contact:sub_123');
    expect(rows[0]?.status).toBe('pending');
  });

  it('updates status and attempt count transitions', () => {
    const store = new InMemoryOutboxStore();
    const record = store.enqueue('forms:contact:sub_123', { event: 'forms.submission.received' });

    store.markRetrying(record.id, 'temporary failure', 1000);
    const retrying = store.getById(record.id);
    expect(retrying?.status).toBe('retrying');
    expect(retrying?.attemptCount).toBe(1);
    expect(retrying?.lastError).toBe('temporary failure');
    expect(retrying?.nextAttemptAt).not.toBeNull();
    expect(retrying?.eventKey).toBe('forms:contact:sub_123');

    store.markFailed(record.id, 'permanent failure');
    const failed = store.getById(record.id);
    expect(failed?.status).toBe('failed');
    expect(failed?.attemptCount).toBe(2);

    store.markSent(record.id);
    const sent = store.getById(record.id);
    expect(sent?.status).toBe('sent');
    expect(sent?.attemptCount).toBe(3);
    expect(sent?.sentAt).not.toBeNull();
    expect(sent?.lastError).toBeNull();
  });
});
