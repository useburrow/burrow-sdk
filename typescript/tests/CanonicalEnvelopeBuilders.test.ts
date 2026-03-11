import { describe, expect, it } from 'vitest';
import { CanonicalEnvelopeBuilders } from '../src/events/CanonicalEnvelopeBuilders.js';
import { ChannelRoutingResolver } from '../src/events/ChannelRoutingResolver.js';

describe('CanonicalEnvelopeBuilders ecommerce helpers', () => {
  const routing = new ChannelRoutingResolver({
    projectId: 'prj_123',
    projectSourceIds: { ecommerce: 'src_ecom_123' },
    clientId: 'cli_123',
  });

  it('builds order.placed with enrichment and lifecycle fields', () => {
    const event = CanonicalEnvelopeBuilders.buildEcommerceOrderPlacedEvent(
      {
        organizationId: 'org_123',
        orderId: 'ord_123',
        externalEntityId: 'woo:ord_123',
        orderTotal: 120.5,
        currency: 'USD',
        itemCount: 2,
        submittedAt: '2026-03-01T00:00:00.000Z',
        tax: 8.25,
        subtotal: 112.25,
        provider: 'woocommerce',
        customerToken: 'cust_tok_1',
        isGuest: 'false',
        orderSequence: '3',
        isNewCustomer: 'false',
        paymentMethod: 'stripe',
        shippingCountry: 'US',
        shippingRegion: 'CA',
        shippingMethod: 'express',
      },
      routing
    );

    expect(event.event).toBe('order.placed');
    expect(event.isLifecycle).toBe(true);
    expect(event.entityType).toBe('order');
    expect(event.externalEntityId).toBe('woo:ord_123');
    expect(event.state).toBe('placed');
    expect(event.properties.tax).toBe(8.25);
    expect(event.properties.subtotal).toBe(112.25);
    expect(event.tags.customerToken).toBe('cust_tok_1');
    expect(event.tags.couponCode).toBeUndefined();
  });

  it('omits couponCode tag when not provided', () => {
    const event = CanonicalEnvelopeBuilders.buildEcommerceOrderPlacedEvent(
      {
        organizationId: 'org_123',
        orderId: 'ord_123',
        orderTotal: 90,
        currency: 'USD',
        itemCount: 1,
        submittedAt: '2026-03-01T00:00:00.000Z',
      },
      routing
    );

    expect(event.tags.couponCode).toBeUndefined();
  });

  it('adds customerToken to item/fulfilled/refunded/cancelled helpers', () => {
    const item = CanonicalEnvelopeBuilders.buildEcommerceItemPurchasedEvent(
      {
        organizationId: 'org_123',
        orderId: 'ord_123',
        productId: 'sku_1',
        productName: 'Widget',
        quantity: 1,
        unitPrice: 20,
        lineTotal: 20,
        currency: 'USD',
        submittedAt: '2026-03-01T00:00:00.000Z',
        customerToken: 'cust_tok_1',
      },
      routing
    );
    expect(item.tags.customerToken).toBe('cust_tok_1');

    const fulfilled = CanonicalEnvelopeBuilders.buildEcommerceOrderFulfilledEvent(
      {
        organizationId: 'org_123',
        orderId: 'ord_123',
        externalEntityId: 'woo:ord_123',
        orderTotal: 20,
        currency: 'USD',
        customerToken: 'cust_tok_1',
      },
      routing
    );
    expect(fulfilled.event).toBe('order.fulfilled');
    expect(fulfilled.state).toBe('fulfilled');
    expect(fulfilled.externalEntityId).toBe('woo:ord_123');
    expect(fulfilled.tags.customerToken).toBe('cust_tok_1');

    const refunded = CanonicalEnvelopeBuilders.buildEcommerceOrderRefundedEvent(
      {
        organizationId: 'org_123',
        orderId: 'ord_123',
        externalEntityId: 'woo:ord_123',
        orderTotal: 20,
        currency: 'USD',
        customerToken: 'cust_tok_1',
      },
      routing
    );
    expect(refunded.event).toBe('order.refunded');
    expect(refunded.state).toBe('refunded');

    const cancelled = CanonicalEnvelopeBuilders.buildEcommerceOrderCancelledEvent(
      {
        organizationId: 'org_123',
        orderId: 'ord_123',
        externalEntityId: 'woo:ord_123',
        orderTotal: 20,
        currency: 'USD',
        customerToken: 'cust_tok_1',
      },
      routing
    );
    expect(cancelled.event).toBe('order.cancelled');
    expect(cancelled.state).toBe('cancelled');
  });
});
