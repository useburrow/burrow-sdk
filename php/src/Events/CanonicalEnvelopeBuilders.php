<?php

declare(strict_types=1);

namespace Burrow\Sdk\Events;

use Burrow\Sdk\Events\Exception\EventContractException;

final class CanonicalEnvelopeBuilders
{
    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function buildSystemStackSnapshotEvent(array $input, ChannelRoutingResolver $routing): array
    {
        self::assertRequiredObjectKeys($input, ['cms', 'runtime']);
        self::assertRequiredArrayKeys($input, ['plugins']);
        self::assertRequiredNumericKeys($input, ['updatesAvailable', 'totalPlugins']);
        self::assertRequiredObjectKeys($input, ['tags']);

        $resolved = $routing->getRoutingForChannel('system');

        return EventEnvelopeBuilder::build([
            'organizationId' => $input['organizationId'] ?? null,
            'clientId' => $resolved['clientId'] ?? $input['clientId'] ?? null,
            'projectId' => $resolved['projectId'],
            'projectSourceId' => $resolved['projectSourceId'],
            'channel' => 'system',
            'event' => 'system.stack.snapshot',
            'timestamp' => $input['timestamp'] ?? gmdate('c'),
            'icon' => 'layers',
            'properties' => [
                'cms' => $input['cms'],
                'runtime' => $input['runtime'],
                'plugins' => $input['plugins'],
                'updatesAvailable' => $input['updatesAvailable'],
                'totalPlugins' => $input['totalPlugins'],
            ],
            'tags' => $input['tags'],
        ], ['strictNames' => true]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function buildSystemHeartbeatEvent(array $input, ChannelRoutingResolver $routing): array
    {
        self::assertRequiredNumericKeys($input, ['responseMs']);
        $resolved = $routing->getRoutingForChannel('system');

        return EventEnvelopeBuilder::build([
            'organizationId' => $input['organizationId'] ?? null,
            'clientId' => $resolved['clientId'] ?? $input['clientId'] ?? null,
            'projectId' => $resolved['projectId'],
            'projectSourceId' => $resolved['projectSourceId'],
            'channel' => 'system',
            'event' => 'system.heartbeat.ping',
            'timestamp' => $input['timestamp'] ?? gmdate('c'),
            'icon' => 'heart',
            'properties' => [
                'responseMs' => $input['responseMs'],
            ],
            'tags' => is_array($input['tags'] ?? null) ? $input['tags'] : [],
        ], ['strictNames' => true]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function buildEcommerceOrderPlacedEvent(array $input, ChannelRoutingResolver $routing): array
    {
        self::assertRequiredStringKeys($input, ['orderId', 'currency', 'submittedAt']);
        self::assertRequiredNumericKeys($input, ['itemCount']);
        if (!isset($input['orderTotal']) && !isset($input['total'])) {
            throw new EventContractException(
                errorCode: 'MISSING_REQUIRED_PROPERTY',
                message: 'orderTotal or total is required for order.placed.'
            );
        }

        $resolved = $routing->getRoutingForChannel('ecommerce');
        $properties = [
            'orderId' => $input['orderId'],
            'orderTotal' => $input['orderTotal'] ?? $input['total'],
            'currency' => $input['currency'],
            'itemCount' => $input['itemCount'],
            'submittedAt' => $input['submittedAt'],
        ];
        if (isset($input['tax']) && is_numeric($input['tax'])) {
            $properties['tax'] = $input['tax'];
        }
        if (isset($input['subtotal']) && is_numeric($input['subtotal'])) {
            $properties['subtotal'] = $input['subtotal'];
        }

        return EventEnvelopeBuilder::build([
            'organizationId' => $input['organizationId'] ?? null,
            'clientId' => $resolved['clientId'] ?? $input['clientId'] ?? null,
            'projectId' => $resolved['projectId'],
            'projectSourceId' => $resolved['projectSourceId'],
            'channel' => 'ecommerce',
            'event' => 'ecommerce.order.placed',
            'timestamp' => $input['timestamp'] ?? gmdate('c'),
            'icon' => 'shopping-cart',
            'properties' => $properties,
            'tags' => self::buildStringTags($input, [
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
            ], true),
            'isLifecycle' => true,
            'entityType' => 'order',
            'externalEntityId' => self::readOptionalString($input, 'externalEntityId'),
            'state' => 'placed',
        ], ['strictNames' => true]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function buildEcommerceItemPurchasedEvent(array $input, ChannelRoutingResolver $routing): array
    {
        self::assertRequiredStringKeys($input, ['orderId', 'productId', 'productName', 'currency', 'submittedAt']);
        self::assertRequiredNumericKeys($input, ['quantity', 'unitPrice', 'lineTotal']);

        $resolved = $routing->getRoutingForChannel('ecommerce');
        return EventEnvelopeBuilder::build([
            'organizationId' => $input['organizationId'] ?? null,
            'clientId' => $resolved['clientId'] ?? $input['clientId'] ?? null,
            'projectId' => $resolved['projectId'],
            'projectSourceId' => $resolved['projectSourceId'],
            'channel' => 'ecommerce',
            'event' => 'ecommerce.item.purchased',
            'timestamp' => $input['timestamp'] ?? gmdate('c'),
            'icon' => 'shopping-cart',
            'properties' => [
                'orderId' => $input['orderId'],
                'productId' => $input['productId'],
                'productName' => $input['productName'],
                'quantity' => $input['quantity'],
                'unitPrice' => $input['unitPrice'],
                'lineTotal' => $input['lineTotal'],
                'currency' => $input['currency'],
                'submittedAt' => $input['submittedAt'],
            ],
            'tags' => self::buildStringTags($input, ['provider', 'customerToken'], true),
        ], ['strictNames' => true]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function buildEcommerceOrderFulfilledEvent(array $input, ChannelRoutingResolver $routing): array
    {
        self::assertRequiredStringKeys($input, ['orderId', 'currency']);
        if (!isset($input['orderTotal']) && !isset($input['total'])) {
            throw new EventContractException(
                errorCode: 'MISSING_REQUIRED_PROPERTY',
                message: 'orderTotal or total is required for order.fulfilled.'
            );
        }

        $resolved = $routing->getRoutingForChannel('ecommerce');
        return EventEnvelopeBuilder::build([
            'organizationId' => $input['organizationId'] ?? null,
            'clientId' => $resolved['clientId'] ?? $input['clientId'] ?? null,
            'projectId' => $resolved['projectId'],
            'projectSourceId' => $resolved['projectSourceId'],
            'channel' => 'ecommerce',
            'event' => 'ecommerce.order.fulfilled',
            'timestamp' => $input['timestamp'] ?? gmdate('c'),
            'icon' => 'shopping-cart',
            'properties' => [
                'orderId' => $input['orderId'],
                'orderTotal' => $input['orderTotal'] ?? $input['total'],
                'currency' => $input['currency'],
            ],
            'tags' => self::buildStringTags($input, ['provider', 'currency', 'customerToken'], true),
            'isLifecycle' => true,
            'entityType' => 'order',
            'externalEntityId' => self::readOptionalString($input, 'externalEntityId'),
            'state' => 'fulfilled',
        ], ['strictNames' => true]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function buildEcommerceOrderRefundedEvent(array $input, ChannelRoutingResolver $routing): array
    {
        self::assertRequiredStringKeys($input, ['orderId', 'currency']);
        if (!isset($input['orderTotal']) && !isset($input['total'])) {
            throw new EventContractException(
                errorCode: 'MISSING_REQUIRED_PROPERTY',
                message: 'orderTotal or total is required for order.refunded.'
            );
        }

        $resolved = $routing->getRoutingForChannel('ecommerce');
        return EventEnvelopeBuilder::build([
            'organizationId' => $input['organizationId'] ?? null,
            'clientId' => $resolved['clientId'] ?? $input['clientId'] ?? null,
            'projectId' => $resolved['projectId'],
            'projectSourceId' => $resolved['projectSourceId'],
            'channel' => 'ecommerce',
            'event' => 'ecommerce.order.refunded',
            'timestamp' => $input['timestamp'] ?? gmdate('c'),
            'icon' => 'shopping-cart',
            'properties' => [
                'orderId' => $input['orderId'],
                'orderTotal' => $input['orderTotal'] ?? $input['total'],
                'currency' => $input['currency'],
            ],
            'tags' => self::buildStringTags($input, ['provider', 'currency', 'customerToken'], true),
            'isLifecycle' => true,
            'entityType' => 'order',
            'externalEntityId' => self::readOptionalString($input, 'externalEntityId'),
            'state' => 'refunded',
        ], ['strictNames' => true]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function buildEcommerceOrderCancelledEvent(array $input, ChannelRoutingResolver $routing): array
    {
        self::assertRequiredStringKeys($input, ['orderId', 'currency']);
        if (!isset($input['orderTotal']) && !isset($input['total'])) {
            throw new EventContractException(
                errorCode: 'MISSING_REQUIRED_PROPERTY',
                message: 'orderTotal or total is required for order.cancelled.'
            );
        }

        $resolved = $routing->getRoutingForChannel('ecommerce');
        return EventEnvelopeBuilder::build([
            'organizationId' => $input['organizationId'] ?? null,
            'clientId' => $resolved['clientId'] ?? $input['clientId'] ?? null,
            'projectId' => $resolved['projectId'],
            'projectSourceId' => $resolved['projectSourceId'],
            'channel' => 'ecommerce',
            'event' => 'ecommerce.order.cancelled',
            'timestamp' => $input['timestamp'] ?? gmdate('c'),
            'icon' => 'shopping-cart',
            'properties' => [
                'orderId' => $input['orderId'],
                'orderTotal' => $input['orderTotal'] ?? $input['total'],
                'currency' => $input['currency'],
            ],
            'tags' => self::buildStringTags($input, ['provider', 'currency', 'customerToken'], true),
            'isLifecycle' => true,
            'entityType' => 'order',
            'externalEntityId' => self::readOptionalString($input, 'externalEntityId'),
            'state' => 'cancelled',
        ], ['strictNames' => true]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function buildEcommerceCartItemAddedEvent(array $input, ChannelRoutingResolver $routing): array
    {
        self::assertRequiredStringKeys($input, ['productId', 'productName', 'variantName', 'currency']);
        self::assertRequiredNumericKeys($input, ['quantity', 'unitPrice', 'lineTotal', 'cartTotal', 'cartItemCount']);
        $resolved = $routing->getRoutingForChannel('ecommerce');

        return EventEnvelopeBuilder::build([
            'organizationId' => $input['organizationId'] ?? null,
            'clientId' => $resolved['clientId'] ?? $input['clientId'] ?? null,
            'projectId' => $resolved['projectId'],
            'projectSourceId' => $resolved['projectSourceId'],
            'channel' => 'ecommerce',
            'event' => 'ecommerce.cart.added',
            'timestamp' => $input['timestamp'] ?? gmdate('c'),
            'icon' => 'package-plus',
            'properties' => [
                'productId' => $input['productId'],
                'productName' => $input['productName'],
                'variantName' => $input['variantName'],
                'quantity' => $input['quantity'],
                'unitPrice' => $input['unitPrice'],
                'lineTotal' => $input['lineTotal'],
                'currency' => $input['currency'],
                'cartTotal' => $input['cartTotal'],
                'cartItemCount' => $input['cartItemCount'],
            ],
            'tags' => self::buildStringTags($input, ['provider', 'currency', 'customerToken', 'productId', 'productName', 'category'], true),
        ], ['strictNames' => true]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function buildEcommerceCartItemRemovedEvent(array $input, ChannelRoutingResolver $routing): array
    {
        self::assertRequiredStringKeys($input, ['productId', 'productName', 'currency']);
        self::assertRequiredNumericKeys($input, ['quantity', 'cartTotal', 'cartItemCount']);
        $resolved = $routing->getRoutingForChannel('ecommerce');

        return EventEnvelopeBuilder::build([
            'organizationId' => $input['organizationId'] ?? null,
            'clientId' => $resolved['clientId'] ?? $input['clientId'] ?? null,
            'projectId' => $resolved['projectId'],
            'projectSourceId' => $resolved['projectSourceId'],
            'channel' => 'ecommerce',
            'event' => 'ecommerce.cart.removed',
            'timestamp' => $input['timestamp'] ?? gmdate('c'),
            'icon' => 'package-minus',
            'properties' => [
                'productId' => $input['productId'],
                'productName' => $input['productName'],
                'quantity' => $input['quantity'],
                'currency' => $input['currency'],
                'cartTotal' => $input['cartTotal'],
                'cartItemCount' => $input['cartItemCount'],
            ],
            'tags' => self::buildStringTags($input, ['provider', 'currency', 'customerToken', 'productId', 'productName', 'category'], true),
        ], ['strictNames' => true]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function buildEcommerceCheckoutStartedEvent(array $input, ChannelRoutingResolver $routing): array
    {
        self::assertRequiredStringKeys($input, ['currency']);
        self::assertRequiredNumericKeys($input, ['cartTotal', 'cartItemCount']);
        $resolved = $routing->getRoutingForChannel('ecommerce');

        return EventEnvelopeBuilder::build([
            'organizationId' => $input['organizationId'] ?? null,
            'clientId' => $resolved['clientId'] ?? $input['clientId'] ?? null,
            'projectId' => $resolved['projectId'],
            'projectSourceId' => $resolved['projectSourceId'],
            'channel' => 'ecommerce',
            'event' => 'ecommerce.checkout.started',
            'timestamp' => $input['timestamp'] ?? gmdate('c'),
            'icon' => 'credit-card',
            'properties' => [
                'cartTotal' => $input['cartTotal'],
                'cartItemCount' => $input['cartItemCount'],
                'currency' => $input['currency'],
            ],
            'tags' => self::buildStringTags($input, ['provider', 'currency', 'customerToken', 'isGuest'], true),
        ], ['strictNames' => true]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function buildEcommerceCheckoutAbandonedEvent(array $input, ChannelRoutingResolver $routing): array
    {
        self::assertRequiredStringKeys($input, ['currency', 'externalEntityId']);
        self::assertRequiredNumericKeys($input, ['cartTotal', 'cartItemCount', 'minutesSinceCheckout']);
        $resolved = $routing->getRoutingForChannel('ecommerce');

        return EventEnvelopeBuilder::build([
            'organizationId' => $input['organizationId'] ?? null,
            'clientId' => $resolved['clientId'] ?? $input['clientId'] ?? null,
            'projectId' => $resolved['projectId'],
            'projectSourceId' => $resolved['projectSourceId'],
            'channel' => 'ecommerce',
            'event' => 'ecommerce.checkout.abandoned',
            'timestamp' => $input['timestamp'] ?? gmdate('c'),
            'icon' => 'hourglass',
            'isLifecycle' => true,
            'entityType' => 'checkout',
            'externalEntityId' => self::readOptionalString($input, 'externalEntityId'),
            'state' => 'abandoned',
            'properties' => [
                'cartTotal' => $input['cartTotal'],
                'cartItemCount' => $input['cartItemCount'],
                'currency' => $input['currency'],
                'minutesSinceCheckout' => $input['minutesSinceCheckout'],
            ],
            'tags' => self::buildStringTags($input, ['provider', 'currency', 'customerToken'], true),
        ], ['strictNames' => true]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function buildEcommerceCartAbandonedEvent(array $input, ChannelRoutingResolver $routing): array
    {
        self::assertRequiredStringKeys($input, ['currency', 'externalEntityId']);
        self::assertRequiredNumericKeys($input, ['cartTotal', 'cartItemCount', 'minutesSinceLastActivity']);
        $resolved = $routing->getRoutingForChannel('ecommerce');

        return EventEnvelopeBuilder::build([
            'organizationId' => $input['organizationId'] ?? null,
            'clientId' => $resolved['clientId'] ?? $input['clientId'] ?? null,
            'projectId' => $resolved['projectId'],
            'projectSourceId' => $resolved['projectSourceId'],
            'channel' => 'ecommerce',
            'event' => 'ecommerce.cart.abandoned',
            'timestamp' => $input['timestamp'] ?? gmdate('c'),
            'icon' => 'clock-fading',
            'isLifecycle' => true,
            'entityType' => 'cart',
            'externalEntityId' => self::readOptionalString($input, 'externalEntityId'),
            'state' => 'abandoned',
            'properties' => [
                'cartTotal' => $input['cartTotal'],
                'cartItemCount' => $input['cartItemCount'],
                'currency' => $input['currency'],
                'minutesSinceLastActivity' => $input['minutesSinceLastActivity'],
            ],
            'tags' => self::buildStringTags($input, ['provider', 'currency', 'customerToken'], true),
        ], ['strictNames' => true]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function buildEcommercePaymentFailedEvent(array $input, ChannelRoutingResolver $routing): array
    {
        self::assertRequiredStringKeys($input, ['orderId', 'currency', 'failureReason', 'paymentMethod']);
        self::assertRequiredNumericKeys($input, ['cartTotal']);
        $resolved = $routing->getRoutingForChannel('ecommerce');

        return EventEnvelopeBuilder::build([
            'organizationId' => $input['organizationId'] ?? null,
            'clientId' => $resolved['clientId'] ?? $input['clientId'] ?? null,
            'projectId' => $resolved['projectId'],
            'projectSourceId' => $resolved['projectSourceId'],
            'channel' => 'ecommerce',
            'event' => 'ecommerce.payment.failed',
            'timestamp' => $input['timestamp'] ?? gmdate('c'),
            'icon' => 'circle-alert',
            'properties' => [
                'orderId' => $input['orderId'],
                'cartTotal' => $input['cartTotal'],
                'currency' => $input['currency'],
                'failureReason' => $input['failureReason'],
                'paymentMethod' => $input['paymentMethod'],
            ],
            'tags' => self::buildStringTags($input, ['provider', 'currency', 'customerToken', 'paymentMethod'], true),
        ], ['strictNames' => true]);
    }

    /**
     * Recovery can follow either ecommerce.cart.abandoned or ecommerce.checkout.abandoned,
     * matched by customerToken across the abandonment and subsequent order events.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function buildEcommerceCartRecoveredEvent(array $input, ChannelRoutingResolver $routing): array
    {
        self::assertRequiredStringKeys($input, ['orderId', 'currency']);
        self::assertRequiredNumericKeys($input, ['orderTotal', 'originalCartTotal', 'minutesSinceAbandonment']);
        $resolved = $routing->getRoutingForChannel('ecommerce');

        return EventEnvelopeBuilder::build([
            'organizationId' => $input['organizationId'] ?? null,
            'clientId' => $resolved['clientId'] ?? $input['clientId'] ?? null,
            'projectId' => $resolved['projectId'],
            'projectSourceId' => $resolved['projectSourceId'],
            'channel' => 'ecommerce',
            'event' => 'ecommerce.cart.recovered',
            'timestamp' => $input['timestamp'] ?? gmdate('c'),
            'icon' => 'rotate-ccw',
            'properties' => [
                'orderId' => $input['orderId'],
                'orderTotal' => $input['orderTotal'],
                'originalCartTotal' => $input['originalCartTotal'],
                'currency' => $input['currency'],
                'minutesSinceAbandonment' => $input['minutesSinceAbandonment'],
            ],
            'tags' => self::buildStringTags($input, ['provider', 'currency', 'customerToken'], true),
        ], ['strictNames' => true]);
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $keys
     */
    private static function assertRequiredObjectKeys(array $input, array $keys): void
    {
        foreach ($keys as $key) {
            if (!is_array($input[$key] ?? null)) {
                throw new EventContractException('MISSING_REQUIRED_PROPERTY', sprintf('Missing required object "%s".', $key));
            }
        }
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $keys
     */
    private static function assertRequiredArrayKeys(array $input, array $keys): void
    {
        foreach ($keys as $key) {
            if (!is_array($input[$key] ?? null)) {
                throw new EventContractException('MISSING_REQUIRED_PROPERTY', sprintf('Missing required array "%s".', $key));
            }
        }
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $keys
     */
    private static function assertRequiredStringKeys(array $input, array $keys): void
    {
        foreach ($keys as $key) {
            if (!isset($input[$key]) || trim((string) $input[$key]) === '') {
                throw new EventContractException('MISSING_REQUIRED_PROPERTY', sprintf('Missing required string "%s".', $key));
            }
        }
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $keys
     */
    private static function assertRequiredNumericKeys(array $input, array $keys): void
    {
        foreach ($keys as $key) {
            if (!isset($input[$key]) || !is_numeric($input[$key])) {
                throw new EventContractException('MISSING_REQUIRED_PROPERTY', sprintf('Missing required numeric "%s".', $key));
            }
        }
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $derivedKeys
     * @return array<string,string>
     */
    private static function buildStringTags(array $input, array $derivedKeys, bool $includeInputTags): array
    {
        $tags = [];
        if ($includeInputTags && is_array($input['tags'] ?? null)) {
            foreach ($input['tags'] as $key => $value) {
                if (is_string($key) && is_string($value) && trim($value) !== '') {
                    $tags[$key] = trim($value);
                }
            }
        }

        foreach ($derivedKeys as $key) {
            $value = self::readOptionalString($input, $key);
            if ($value !== null) {
                $tags[$key] = $value;
            }
        }

        $couponCode = self::readOptionalString($input, 'couponCode');
        if ($couponCode !== null) {
            $tags['couponCode'] = $couponCode;
        }

        return $tags;
    }

    /**
     * @param array<string,mixed> $input
     */
    private static function readOptionalString(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
