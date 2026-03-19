import { describe, expect, it } from 'vitest';
import { resolveIconForEvent } from '../src/events/EventIconResolver.js';

describe('resolveIconForEvent', () => {
  it('returns canonical lucide icon for known event mapping', () => {
    expect(resolveIconForEvent('forms', 'forms.submission.received')).toBe('file-signature');
    expect(resolveIconForEvent('system', 'system.heartbeat.ping')).toBe('heart-pulse');
    expect(resolveIconForEvent('ecommerce', 'ecommerce.order.placed')).toBe('shopping-cart');
    expect(resolveIconForEvent('ecommerce', 'ecommerce.order.cancelled')).toBe('circle-x');
    expect(resolveIconForEvent('ecommerce', 'ecommerce.order.fulfilled')).toBe('badge-check');
    expect(resolveIconForEvent('ecommerce', 'ecommerce.order.refunded')).toBe('rotate-ccw');
    expect(resolveIconForEvent('ecommerce', 'ecommerce.item.purchased')).toBe('package');
    expect(resolveIconForEvent('ecommerce', 'ecommerce.cart.added')).toBe('package-plus');
    expect(resolveIconForEvent('ecommerce', 'ecommerce.cart.removed')).toBe('package-minus');
    expect(resolveIconForEvent('ecommerce', 'ecommerce.checkout.started')).toBe('credit-card');
    expect(resolveIconForEvent('ecommerce', 'ecommerce.checkout.abandoned')).toBe('hourglass');
    expect(resolveIconForEvent('ecommerce', 'ecommerce.cart.abandoned')).toBe('clock-fading');
    expect(resolveIconForEvent('ecommerce', 'ecommerce.cart.recovered')).toBe('rotate-ccw');
    expect(resolveIconForEvent('ecommerce', 'ecommerce.payment.failed')).toBe('circle-alert');
  });

  it('falls back to channel default when event is unknown', () => {
    expect(resolveIconForEvent('analytics', 'analytics.unknown.event')).toBe('chart-column');
  });

  it('returns null when channel and event are unknown', () => {
    expect(resolveIconForEvent('unknown-channel', 'unknown.event')).toBeNull();
  });
});
