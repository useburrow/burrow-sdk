import { EventEnvelopeBuilder } from './EventEnvelopeBuilder.js';
import type { ChannelRoutingResolver } from './ChannelRoutingResolver.js';
import type { JsonObject, JsonValue } from '../client/types.js';

type EventInput = Record<string, JsonValue>;

export interface EcommerceCartItemAddedInput extends EventInput {}
export interface EcommerceCartItemRemovedInput extends EventInput {}
export interface EcommerceCheckoutStartedInput extends EventInput {}
export interface EcommerceCheckoutAbandonedInput extends EventInput {}
export interface EcommerceCartAbandonedInput extends EventInput {}
export interface EcommercePaymentFailedInput extends EventInput {}
export interface EcommerceCartRecoveredInput extends EventInput {}

export class CanonicalEnvelopeBuilders {
  static buildEcommerceOrderPlacedEvent(input: EventInput, routing: ChannelRoutingResolver) {
    assertRequiredStringKeys(input, ['orderId', 'currency', 'submittedAt']);
    assertRequiredNumericKeys(input, ['itemCount']);
    if (input.orderTotal === undefined && input.total === undefined) {
      throw new Error('orderTotal or total is required for order.placed.');
    }

    const resolved = routing.getRoutingForChannel('ecommerce');
    const properties: JsonObject = {
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
    const shippingTotal = optionalNumeric(input.shipping);
    if (shippingTotal !== null) {
      properties.shippingTotal = shippingTotal;
    }
    const shippingMethodProp = optionalString(input.shippingMethod);
    if (shippingMethodProp !== null) {
      properties.shippingMethod = shippingMethodProp;
    }

    return EventEnvelopeBuilder.build({
      organizationId: stringOrEmpty(input.organizationId),
      clientId: resolved.clientId ?? stringOrEmpty(input.clientId),
      projectId: resolved.projectId,
      projectSourceId: resolved.projectSourceId,
      channel: 'ecommerce',
      event: 'ecommerce.order.placed',
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
      event: 'ecommerce.item.purchased',
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
    return this.buildOrderLifecycleEvent(input, routing, 'ecommerce.order.fulfilled', 'fulfilled');
  }

  static buildEcommerceOrderRefundedEvent(input: EventInput, routing: ChannelRoutingResolver) {
    return this.buildOrderLifecycleEvent(input, routing, 'ecommerce.order.refunded', 'refunded');
  }

  static buildEcommerceOrderCancelledEvent(input: EventInput, routing: ChannelRoutingResolver) {
    return this.buildOrderLifecycleEvent(input, routing, 'ecommerce.order.cancelled', 'cancelled');
  }

  static buildEcommerceCartItemAddedEvent(input: EcommerceCartItemAddedInput, routing: ChannelRoutingResolver) {
    assertRequiredStringKeys(input, ['productId', 'productName', 'variantName', 'currency']);
    assertRequiredNumericKeys(input, ['quantity', 'unitPrice', 'lineTotal', 'cartTotal', 'cartItemCount']);
    const resolved = routing.getRoutingForChannel('ecommerce');

    return EventEnvelopeBuilder.build({
      organizationId: stringOrEmpty(input.organizationId),
      clientId: resolved.clientId ?? stringOrEmpty(input.clientId),
      projectId: resolved.projectId,
      projectSourceId: resolved.projectSourceId,
      channel: 'ecommerce',
      event: 'ecommerce.cart.added',
      timestamp: stringOrNow(input.timestamp),
      icon: 'package-plus',
      properties: {
        productId: input.productId,
        productName: input.productName,
        variantName: input.variantName,
        quantity: input.quantity,
        unitPrice: input.unitPrice,
        lineTotal: input.lineTotal,
        currency: input.currency,
        cartTotal: input.cartTotal,
        cartItemCount: input.cartItemCount,
      },
      tags: buildStringTags(input, ['provider', 'currency', 'customerToken', 'productId', 'productName', 'category']),
    });
  }

  static buildEcommerceCartItemRemovedEvent(input: EcommerceCartItemRemovedInput, routing: ChannelRoutingResolver) {
    assertRequiredStringKeys(input, ['productId', 'productName', 'currency']);
    assertRequiredNumericKeys(input, ['quantity', 'cartTotal', 'cartItemCount']);
    const resolved = routing.getRoutingForChannel('ecommerce');

    return EventEnvelopeBuilder.build({
      organizationId: stringOrEmpty(input.organizationId),
      clientId: resolved.clientId ?? stringOrEmpty(input.clientId),
      projectId: resolved.projectId,
      projectSourceId: resolved.projectSourceId,
      channel: 'ecommerce',
      event: 'ecommerce.cart.removed',
      timestamp: stringOrNow(input.timestamp),
      icon: 'package-minus',
      properties: {
        productId: input.productId,
        productName: input.productName,
        quantity: input.quantity,
        currency: input.currency,
        cartTotal: input.cartTotal,
        cartItemCount: input.cartItemCount,
      },
      tags: buildStringTags(input, ['provider', 'currency', 'customerToken', 'productId', 'productName', 'category']),
    });
  }

  static buildEcommerceCheckoutStartedEvent(input: EcommerceCheckoutStartedInput, routing: ChannelRoutingResolver) {
    assertRequiredStringKeys(input, ['currency']);
    assertRequiredNumericKeys(input, ['cartTotal', 'cartItemCount']);
    const resolved = routing.getRoutingForChannel('ecommerce');

    return EventEnvelopeBuilder.build({
      organizationId: stringOrEmpty(input.organizationId),
      clientId: resolved.clientId ?? stringOrEmpty(input.clientId),
      projectId: resolved.projectId,
      projectSourceId: resolved.projectSourceId,
      channel: 'ecommerce',
      event: 'ecommerce.checkout.started',
      timestamp: stringOrNow(input.timestamp),
      icon: 'credit-card',
      properties: {
        cartTotal: input.cartTotal,
        cartItemCount: input.cartItemCount,
        currency: input.currency,
      },
      tags: buildStringTags(input, ['provider', 'currency', 'customerToken', 'isGuest']),
    });
  }

  static buildEcommerceCheckoutAbandonedEvent(input: EcommerceCheckoutAbandonedInput, routing: ChannelRoutingResolver) {
    assertRequiredStringKeys(input, ['currency', 'externalEntityId']);
    assertRequiredNumericKeys(input, ['cartTotal', 'cartItemCount', 'minutesSinceCheckout']);
    const resolved = routing.getRoutingForChannel('ecommerce');

    return EventEnvelopeBuilder.build({
      organizationId: stringOrEmpty(input.organizationId),
      clientId: resolved.clientId ?? stringOrEmpty(input.clientId),
      projectId: resolved.projectId,
      projectSourceId: resolved.projectSourceId,
      channel: 'ecommerce',
      event: 'ecommerce.checkout.abandoned',
      timestamp: stringOrNow(input.timestamp),
      icon: 'hourglass',
      isLifecycle: true,
      entityType: 'checkout',
      externalEntityId: optionalString(input.externalEntityId),
      state: 'abandoned',
      properties: {
        cartTotal: input.cartTotal,
        cartItemCount: input.cartItemCount,
        currency: input.currency,
        minutesSinceCheckout: input.minutesSinceCheckout,
      },
      tags: buildStringTags(input, ['provider', 'currency', 'customerToken']),
    });
  }

  static buildEcommerceCartAbandonedEvent(input: EcommerceCartAbandonedInput, routing: ChannelRoutingResolver) {
    assertRequiredStringKeys(input, ['currency', 'externalEntityId']);
    assertRequiredNumericKeys(input, ['cartTotal', 'cartItemCount', 'minutesSinceLastActivity']);
    const resolved = routing.getRoutingForChannel('ecommerce');

    return EventEnvelopeBuilder.build({
      organizationId: stringOrEmpty(input.organizationId),
      clientId: resolved.clientId ?? stringOrEmpty(input.clientId),
      projectId: resolved.projectId,
      projectSourceId: resolved.projectSourceId,
      channel: 'ecommerce',
      event: 'ecommerce.cart.abandoned',
      timestamp: stringOrNow(input.timestamp),
      icon: 'clock-fading',
      isLifecycle: true,
      entityType: 'cart',
      externalEntityId: optionalString(input.externalEntityId),
      state: 'abandoned',
      properties: {
        cartTotal: input.cartTotal,
        cartItemCount: input.cartItemCount,
        currency: input.currency,
        minutesSinceLastActivity: input.minutesSinceLastActivity,
      },
      tags: buildStringTags(input, ['provider', 'currency', 'customerToken']),
    });
  }

  static buildEcommercePaymentFailedEvent(input: EcommercePaymentFailedInput, routing: ChannelRoutingResolver) {
    assertRequiredStringKeys(input, ['orderId', 'currency', 'failureReason', 'paymentMethod']);
    assertRequiredNumericKeys(input, ['cartTotal']);
    const resolved = routing.getRoutingForChannel('ecommerce');

    return EventEnvelopeBuilder.build({
      organizationId: stringOrEmpty(input.organizationId),
      clientId: resolved.clientId ?? stringOrEmpty(input.clientId),
      projectId: resolved.projectId,
      projectSourceId: resolved.projectSourceId,
      channel: 'ecommerce',
      event: 'ecommerce.payment.failed',
      timestamp: stringOrNow(input.timestamp),
      icon: 'circle-alert',
      properties: {
        orderId: input.orderId,
        cartTotal: input.cartTotal,
        currency: input.currency,
        failureReason: input.failureReason,
        paymentMethod: input.paymentMethod,
      },
      tags: buildStringTags(input, ['provider', 'currency', 'customerToken', 'paymentMethod']),
    });
  }

  /**
   * Recovery can follow either ecommerce.cart.abandoned or ecommerce.checkout.abandoned,
   * matched by customerToken across the abandonment and subsequent order events.
   */
  static buildEcommerceCartRecoveredEvent(input: EcommerceCartRecoveredInput, routing: ChannelRoutingResolver) {
    assertRequiredStringKeys(input, ['orderId', 'currency']);
    assertRequiredNumericKeys(input, ['orderTotal', 'originalCartTotal', 'minutesSinceAbandonment']);
    const resolved = routing.getRoutingForChannel('ecommerce');

    return EventEnvelopeBuilder.build({
      organizationId: stringOrEmpty(input.organizationId),
      clientId: resolved.clientId ?? stringOrEmpty(input.clientId),
      projectId: resolved.projectId,
      projectSourceId: resolved.projectSourceId,
      channel: 'ecommerce',
      event: 'ecommerce.cart.recovered',
      timestamp: stringOrNow(input.timestamp),
      icon: 'rotate-ccw',
      properties: {
        orderId: input.orderId,
        orderTotal: input.orderTotal,
        originalCartTotal: input.originalCartTotal,
        currency: input.currency,
        minutesSinceAbandonment: input.minutesSinceAbandonment,
      },
      tags: buildStringTags(input, ['provider', 'currency', 'customerToken']),
    });
  }

  private static buildOrderLifecycleEvent(
    input: EventInput,
    routing: ChannelRoutingResolver,
    event: 'ecommerce.order.fulfilled' | 'ecommerce.order.refunded' | 'ecommerce.order.cancelled',
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
    const value = input[key];
    if (typeof value !== 'number' || Number.isNaN(value)) {
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

function optionalString(value: JsonValue | undefined): string | null {
  if (typeof value !== 'string') {
    return null;
  }
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}

/** Numeric scalar for optional totals (matches PHP is_numeric on ingestion payloads). */
function optionalNumeric(value: JsonValue | undefined): number | null {
  if (typeof value === 'number' && !Number.isNaN(value)) {
    return value;
  }
  if (typeof value === 'string') {
    const trimmed = value.trim();
    if (trimmed === '') {
      return null;
    }
    const n = Number(trimmed);
    return Number.isNaN(n) ? null : n;
  }
  return null;
}

function stringOrNow(value: JsonValue | undefined): string {
  const parsed = optionalString(value);
  return parsed ?? new Date().toISOString();
}

function stringOrEmpty(value: JsonValue | undefined): string {
  return optionalString(value) ?? '';
}
