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
    expect(event.isLifecycle).toBe(false);
    expect(event.properties).toEqual({});
    expect(event.tags).toEqual({});
    expect(event.projectId).toBeNull();
    expect(event.integrationId).toBeNull();
    expect(event.clientSourceId).toBeNull();
    expect(event.icon).toBe('file-signature');
    expect(event.source).toBe('wordpress-plugin');
    expect(event.entityType).toBeNull();
    expect(event.externalEntityId).toBeNull();
    expect(event.externalEventId).toBeNull();
    expect(event.state).toBeNull();
    expect(event.stateChangedAt).toBeNull();
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

  it('accepts lifecycle override fields', () => {
    const event = EventEnvelopeBuilder.build({
      organizationId: 'org_123',
      clientId: 'cli_123',
      channel: 'system',
      event: 'system.lifecycle.updated',
      timestamp: '2026-03-09T00:00:00.000Z',
      isLifecycle: true,
      entityType: 'contract',
      externalEntityId: 'form_123',
      externalEventId: 'evt_123',
      state: 'synced',
      stateChangedAt: '2026-03-09T00:00:30.000Z',
    });

    expect(event.isLifecycle).toBe(true);
    expect(event.entityType).toBe('contract');
    expect(event.externalEntityId).toBe('form_123');
    expect(event.externalEventId).toBe('evt_123');
    expect(event.state).toBe('synced');
    expect(event.stateChangedAt).toBe('2026-03-09T00:00:30.000Z');
  });

  it('preserves explicit icon override', () => {
    const event = EventEnvelopeBuilder.build({
      organizationId: 'org_123',
      clientId: 'cli_123',
      channel: 'forms',
      event: 'forms.submission.received',
      timestamp: '2026-03-09T00:00:00.000Z',
      icon: 'star',
    });

    expect(event.icon).toBe('star');
  });

  it('resolves provider-specific source for forms and ecommerce', () => {
    const formsEvent = EventEnvelopeBuilder.build({
      organizationId: 'org_123',
      clientId: 'cli_123',
      channel: 'forms',
      event: 'forms.submission.received',
      timestamp: '2026-03-09T00:00:00.000Z',
      properties: { provider: 'gravityforms' },
    });
    expect(formsEvent.source).toBe('gravity-forms');

    const ecommerceEvent = EventEnvelopeBuilder.build({
      organizationId: 'org_123',
      clientId: 'cli_123',
      channel: 'ecommerce',
      event: 'order.placed',
      timestamp: '2026-03-09T00:00:00.000Z',
      tags: { provider: 'woocommerce' },
    });
    expect(ecommerceEvent.source).toBe('woocommerce');
  });

  it('falls back to platform source for unknown provider and preserves explicit source', () => {
    const fallbackEvent = EventEnvelopeBuilder.build({
      organizationId: 'org_123',
      clientId: 'cli_123',
      channel: 'forms',
      event: 'forms.submission.received',
      timestamp: '2026-03-09T00:00:00.000Z',
      platform: 'craft',
      properties: { provider: 'custom-form-plugin' },
    });
    expect(fallbackEvent.source).toBe('craft-plugin');

    const explicitSourceEvent = EventEnvelopeBuilder.build({
      organizationId: 'org_123',
      clientId: 'cli_123',
      channel: 'forms',
      event: 'forms.submission.received',
      timestamp: '2026-03-09T00:00:00.000Z',
      source: 'explicit-source',
      properties: { provider: 'gravityforms' },
    });
    expect(explicitSourceEvent.source).toBe('explicit-source');
  });
});
