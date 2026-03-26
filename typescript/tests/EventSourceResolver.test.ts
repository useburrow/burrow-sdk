import { describe, expect, it } from 'vitest';
import { getDefaultEventSource, resolveSourceForEvent } from '../src/events/EventSourceResolver.js';

describe('EventSourceResolver', () => {
  it('maps platform to default CMS plugin source', () => {
    expect(getDefaultEventSource('craft')).toBe('craft-plugin');
    expect(getDefaultEventSource('Craft')).toBe('craft-plugin');
    expect(getDefaultEventSource('wordpress')).toBe('wordpress-plugin');
    expect(getDefaultEventSource(undefined)).toBe('wordpress-plugin');
    expect(getDefaultEventSource(null)).toBe('wordpress-plugin');
    expect(getDefaultEventSource('')).toBe('wordpress-plugin');
  });

  it('resolves canonical forms providers', () => {
    expect(resolveSourceForEvent({ channel: 'forms', properties: { provider: 'gravityforms' } })).toBe(
      'gravity-forms'
    );
    expect(resolveSourceForEvent({ channel: 'forms', properties: { provider: 'fluent-forms' } })).toBe(
      'fluent-forms'
    );
  });

  it('resolves canonical ecommerce providers', () => {
    expect(resolveSourceForEvent({ channel: 'ecommerce', tags: { provider: 'woocommerce' } })).toBe(
      'woocommerce'
    );
    expect(resolveSourceForEvent({ channel: 'ecommerce', properties: { provider: 'craftcommerce' } })).toBe(
      'craft-commerce'
    );
  });

  it('falls back to platform plugin source when provider is unknown', () => {
    expect(
      resolveSourceForEvent({
        channel: 'forms',
        properties: { provider: 'custom-plugin' },
        platform: 'wordpress',
      })
    ).toBe('wordpress-plugin');
    expect(
      resolveSourceForEvent({
        channel: 'forms',
        properties: { provider: 'custom-plugin' },
        platform: 'craft',
      })
    ).toBe('craft-plugin');
  });
});
