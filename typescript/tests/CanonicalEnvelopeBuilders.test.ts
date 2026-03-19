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

    expect(event.event).toBe('ecommerce.order.placed');
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
    expect(item.event).toBe('ecommerce.item.purchased');
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
    expect(fulfilled.event).toBe('ecommerce.order.fulfilled');
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
    expect(refunded.event).toBe('ecommerce.order.refunded');
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
    expect(cancelled.event).toBe('ecommerce.order.cancelled');
    expect(cancelled.state).toBe('cancelled');
  });

  it('builds cart and checkout funnel events', () => {
    const added = CanonicalEnvelopeBuilders.buildEcommerceCartItemAddedEvent(
      {
        organizationId: 'org_123',
        productId: 'sku_1',
        productName: 'Widget',
        variantName: 'Blue / L',
        quantity: 1,
        unitPrice: 20,
        lineTotal: 20,
        currency: 'USD',
        cartTotal: 90,
        cartItemCount: 3,
        customerToken: 'cust_tok_1',
        category: 'apparel',
      },
      routing
    );
    expect(added.event).toBe('ecommerce.cart.added');
    expect(added.properties.unitPrice).toBe(20);
    expect(added.tags.productId).toBe('sku_1');

    const removed = CanonicalEnvelopeBuilders.buildEcommerceCartItemRemovedEvent(
      {
        organizationId: 'org_123',
        productId: 'sku_1',
        productName: 'Widget',
        quantity: 1,
        currency: 'USD',
        cartTotal: 70,
        cartItemCount: 2,
      },
      routing
    );
    expect(removed.event).toBe('ecommerce.cart.removed');
    expect(removed.properties.unitPrice).toBeUndefined();
    expect(removed.properties.lineTotal).toBeUndefined();

    const checkoutStarted = CanonicalEnvelopeBuilders.buildEcommerceCheckoutStartedEvent(
      {
        organizationId: 'org_123',
        cartTotal: 70,
        cartItemCount: 2,
        currency: 'USD',
        isGuest: 'true',
      },
      routing
    );
    expect(checkoutStarted.event).toBe('ecommerce.checkout.started');
    expect(checkoutStarted.tags.isGuest).toBe('true');

    const abandoned = CanonicalEnvelopeBuilders.buildEcommerceCheckoutAbandonedEvent(
      {
        organizationId: 'org_123',
        externalEntityId: 'wc_session_abc123',
        cartTotal: 70,
        cartItemCount: 2,
        currency: 'USD',
        minutesSinceCheckout: 35,
      },
      routing
    );
    expect(abandoned.event).toBe('ecommerce.checkout.abandoned');
    expect(abandoned.isLifecycle).toBe(true);
    expect(abandoned.entityType).toBe('checkout');
    expect(abandoned.state).toBe('abandoned');
    expect(abandoned.externalEntityId).toBe('wc_session_abc123');

    const cartAbandoned = CanonicalEnvelopeBuilders.buildEcommerceCartAbandonedEvent(
      {
        organizationId: 'org_123',
        externalEntityId: 'wc_cart_xyz789',
        cartTotal: 85.5,
        cartItemCount: 3,
        currency: 'USD',
        minutesSinceLastActivity: 60,
        customerToken: 'cust_tok_1',
      },
      routing
    );
    expect(cartAbandoned.event).toBe('ecommerce.cart.abandoned');
    expect(cartAbandoned.isLifecycle).toBe(true);
    expect(cartAbandoned.entityType).toBe('cart');
    expect(cartAbandoned.state).toBe('abandoned');
    expect(cartAbandoned.externalEntityId).toBe('wc_cart_xyz789');
    expect(cartAbandoned.properties.minutesSinceLastActivity).toBe(60);
    expect(cartAbandoned.tags.customerToken).toBe('cust_tok_1');

    const paymentFailed = CanonicalEnvelopeBuilders.buildEcommercePaymentFailedEvent(
      {
        organizationId: 'org_123',
        orderId: 'ord_456',
        cartTotal: 120.5,
        currency: 'USD',
        failureReason: 'card_declined',
        paymentMethod: 'stripe',
        customerToken: 'cust_tok_1',
      },
      routing
    );
    expect(paymentFailed.event).toBe('ecommerce.payment.failed');
    expect(paymentFailed.isLifecycle).toBe(false);
    expect(paymentFailed.properties.failureReason).toBe('card_declined');
    expect(paymentFailed.properties.paymentMethod).toBe('stripe');
    expect(paymentFailed.tags.paymentMethod).toBe('stripe');
    expect(paymentFailed.tags.customerToken).toBe('cust_tok_1');

    const recovered = CanonicalEnvelopeBuilders.buildEcommerceCartRecoveredEvent(
      {
        organizationId: 'org_123',
        orderId: 'ord_123',
        orderTotal: 90,
        originalCartTotal: 70,
        currency: 'USD',
        minutesSinceAbandonment: 12,
      },
      routing
    );
    expect(recovered.event).toBe('ecommerce.cart.recovered');
    expect(recovered.properties.minutesSinceAbandonment).toBe(12);
  });
});
