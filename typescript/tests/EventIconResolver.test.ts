import { describe, expect, it } from 'vitest';
import { resolveIconForEvent } from '../src/events/EventIconResolver.js';

describe('resolveIconForEvent', () => {
  it('returns canonical lucide icon for known event mapping', () => {
    expect(resolveIconForEvent('forms', 'forms.submission.received')).toBe('file-signature');
    expect(resolveIconForEvent('system', 'heartbeat.ping')).toBe('heart-pulse');
    expect(resolveIconForEvent('ecommerce', 'order.placed')).toBe('shopping-cart');
    expect(resolveIconForEvent('ecommerce', 'order.cancelled')).toBe('circle-x');
    expect(resolveIconForEvent('ecommerce', 'order.fulfilled')).toBe('badge-check');
    expect(resolveIconForEvent('ecommerce', 'order.refunded')).toBe('rotate-ccw');
    expect(resolveIconForEvent('ecommerce', 'item.purchased')).toBe('package');
    expect(resolveIconForEvent('ecommerce', 'cart.item.added')).toBe('package-plus');
    expect(resolveIconForEvent('ecommerce', 'cart.item.removed')).toBe('package-minus');
    expect(resolveIconForEvent('ecommerce', 'checkout.started')).toBe('credit-card');
    expect(resolveIconForEvent('ecommerce', 'checkout.abandoned')).toBe('hourglass');
    expect(resolveIconForEvent('ecommerce', 'cart.recovered')).toBe('rotate-ccw');
  });

  it('falls back to channel default when event is unknown', () => {
    expect(resolveIconForEvent('analytics', 'analytics.unknown.event')).toBe('chart-column');
  });

  it('returns null when channel and event are unknown', () => {
    expect(resolveIconForEvent('unknown-channel', 'unknown.event')).toBeNull();
  });
});
