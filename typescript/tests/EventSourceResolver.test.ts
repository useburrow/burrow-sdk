import { describe, expect, it } from 'vitest';
import { resolveSourceForEvent } from '../src/events/EventSourceResolver.js';

describe('EventSourceResolver', () => {
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
