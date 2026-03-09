import { describe, expect, it } from 'vitest';
import { EventEnvelopeBuilder } from '../src/events/EventEnvelopeBuilder.js';

describe('EventEnvelopeBuilder', () => {
  it('builds envelope with defaults', () => {
    const event = EventEnvelopeBuilder.build({
      organizationId: 'org_123',
      clientId: 'cli_123',
      channel: 'forms',
      event: 'forms.submission.received',
      timestamp: '2026-03-09T00:00:00.000Z',
    });

    expect(event.schemaVersion).toBe('1');
    expect(event.properties).toEqual({});
    expect(event.tags).toEqual({});
    expect(event.projectId).toBeNull();
  });

  it('throws when required fields are missing', () => {
    expect(() =>
      EventEnvelopeBuilder.build({
        organizationId: 'org_123',
        clientId: 'cli_123',
        channel: 'forms',
        event: 'forms.submission.received',
        timestamp: '',
      })
    ).toThrow('Missing required field: timestamp');
  });
});
