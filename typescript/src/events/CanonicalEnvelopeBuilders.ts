import { EventEnvelopeBuilder } from './EventEnvelopeBuilder.js';
import type { ChannelRoutingResolver } from './ChannelRoutingResolver.js';

type EventInput = Record<string, unknown>;

export class CanonicalEnvelopeBuilders {
  static buildEcommerceOrderPlacedEvent(input: EventInput, routing: ChannelRoutingResolver) {
    assertRequiredStringKeys(input, ['orderId', 'currency', 'submittedAt']);
    assertRequiredNumericKeys(input, ['itemCount']);
    if (input.orderTotal === undefined && input.total === undefined) {
      throw new Error('orderTotal or total is required for order.placed.');
    }

    const resolved = routing.getRoutingForChannel('ecommerce');
    const properties: Record<string, unknown> = {
      orderId: input.orderId,
      orderTotal: input.orderTotal ?? input.total,
      currency: input.currency,
      itemCount: input.itemCount,
      submittedAt: input.submittedAt,
    };
    if (typeof input.tax === 'number') {
      properties.tax = input.tax;
    }
    if (typeof input.subtotal === 'number') {
      properties.subtotal = input.subtotal;
    }

    return EventEnvelopeBuilder.build({
      organizationId: stringOrEmpty(input.organizationId),
      clientId: resolved.clientId ?? stringOrEmpty(input.clientId),
      projectId: resolved.projectId,
      projectSourceId: resolved.projectSourceId,
      channel: 'ecommerce',
      event: 'order.placed',
      timestamp: stringOrNow(input.timestamp),
      icon: 'shopping-cart',
      properties,
      tags: buildStringTags(input, [
        'provider',
        'currency',
        'customerToken',
        'isGuest',
        'orderSequence',
        'isNewCustomer',
        'paymentMethod',
        'shippingCountry',
        'shippingRegion',
        'shippingMethod',
      ]),
      isLifecycle: true,
      entityType: 'order',
      externalEntityId: optionalString(input.externalEntityId),
      state: 'placed',
    });
  }

  static buildEcommerceItemPurchasedEvent(input: EventInput, routing: ChannelRoutingResolver) {
    assertRequiredStringKeys(input, ['orderId', 'productId', 'productName', 'currency', 'submittedAt']);
    assertRequiredNumericKeys(input, ['quantity', 'unitPrice', 'lineTotal']);

    const resolved = routing.getRoutingForChannel('ecommerce');
    return EventEnvelopeBuilder.build({
      organizationId: stringOrEmpty(input.organizationId),
      clientId: resolved.clientId ?? stringOrEmpty(input.clientId),
      projectId: resolved.projectId,
      projectSourceId: resolved.projectSourceId,
      channel: 'ecommerce',
      event: 'item.purchased',
      timestamp: stringOrNow(input.timestamp),
      icon: 'shopping-cart',
      properties: {
        orderId: input.orderId,
        productId: input.productId,
        productName: input.productName,
        quantity: input.quantity,
        unitPrice: input.unitPrice,
        lineTotal: input.lineTotal,
        currency: input.currency,
        submittedAt: input.submittedAt,
      },
      tags: buildStringTags(input, ['provider', 'customerToken']),
    });
  }

  static buildEcommerceOrderFulfilledEvent(input: EventInput, routing: ChannelRoutingResolver) {
    return this.buildOrderLifecycleEvent(input, routing, 'order.fulfilled', 'fulfilled');
  }

  static buildEcommerceOrderRefundedEvent(input: EventInput, routing: ChannelRoutingResolver) {
    return this.buildOrderLifecycleEvent(input, routing, 'order.refunded', 'refunded');
  }

  static buildEcommerceOrderCancelledEvent(input: EventInput, routing: ChannelRoutingResolver) {
    return this.buildOrderLifecycleEvent(input, routing, 'order.cancelled', 'cancelled');
  }

  private static buildOrderLifecycleEvent(
    input: EventInput,
    routing: ChannelRoutingResolver,
    event: 'order.fulfilled' | 'order.refunded' | 'order.cancelled',
    state: 'fulfilled' | 'refunded' | 'cancelled'
  ) {
    assertRequiredStringKeys(input, ['orderId', 'currency']);
    if (input.orderTotal === undefined && input.total === undefined) {
      throw new Error(`orderTotal or total is required for ${event}.`);
    }
    const resolved = routing.getRoutingForChannel('ecommerce');

    return EventEnvelopeBuilder.build({
      organizationId: stringOrEmpty(input.organizationId),
      clientId: resolved.clientId ?? stringOrEmpty(input.clientId),
      projectId: resolved.projectId,
      projectSourceId: resolved.projectSourceId,
      channel: 'ecommerce',
      event,
      timestamp: stringOrNow(input.timestamp),
      icon: 'shopping-cart',
      properties: {
        orderId: input.orderId,
        orderTotal: input.orderTotal ?? input.total,
        currency: input.currency,
      },
      tags: buildStringTags(input, ['provider', 'currency', 'customerToken']),
      isLifecycle: true,
      entityType: 'order',
      externalEntityId: optionalString(input.externalEntityId),
      state,
    });
  }
}

function assertRequiredStringKeys(input: EventInput, keys: string[]) {
  for (const key of keys) {
    const value = input[key];
    if (typeof value !== 'string' || value.trim() === '') {
      throw new Error(`Missing required string "${key}".`);
    }
  }
}

function assertRequiredNumericKeys(input: EventInput, keys: string[]) {
  for (const key of keys) {
    if (typeof input[key] !== 'number' || Number.isNaN(input[key])) {
      throw new Error(`Missing required numeric "${key}".`);
    }
  }
}

function buildStringTags(input: EventInput, keys: string[]): Record<string, string> {
  const tags: Record<string, string> = {};
  const incoming = input.tags;
  if (incoming && typeof incoming === 'object' && !Array.isArray(incoming)) {
    for (const [key, value] of Object.entries(incoming)) {
      if (typeof value === 'string' && value.trim() !== '') {
        tags[key] = value.trim();
      }
    }
  }

  for (const key of keys) {
    const value = optionalString(input[key]);
    if (value !== null) {
      tags[key] = value;
    }
  }

  const couponCode = optionalString(input.couponCode);
  if (couponCode !== null) {
    tags.couponCode = couponCode;
  }

  return tags;
}

function optionalString(value: unknown): string | null {
  if (typeof value !== 'string') {
    return null;
  }
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}

function stringOrNow(value: unknown): string {
  const parsed = optionalString(value);
  return parsed ?? new Date().toISOString();
}

function stringOrEmpty(value: unknown): string {
  return optionalString(value) ?? '';
}
