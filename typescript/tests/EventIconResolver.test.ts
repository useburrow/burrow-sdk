import { describe, expect, it } from 'vitest';
import { resolveIconForEvent } from '../src/events/EventIconResolver.js';

describe('resolveIconForEvent', () => {
  it('returns canonical lucide icon for known event mapping', () => {
    expect(resolveIconForEvent('forms', 'forms.submission.received')).toBe('file-signature');
    expect(resolveIconForEvent('system', 'system.heartbeat.ping')).toBe('heart-pulse');
    expect(resolveIconForEvent('ecommerce', 'ecommerce.order.placed')).toBe('shopping-cart');
  });

  it('falls back to channel default when event is unknown', () => {
    expect(resolveIconForEvent('analytics', 'analytics.unknown.event')).toBe('chart-column');
  });

  it('returns null when channel and event are unknown', () => {
    expect(resolveIconForEvent('unknown-channel', 'unknown.event')).toBeNull();
  });
});
