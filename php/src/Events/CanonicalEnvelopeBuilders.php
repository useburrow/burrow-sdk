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
            'event' => 'stack.snapshot',
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
            'event' => 'heartbeat.ping',
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
        return EventEnvelopeBuilder::build([
            'organizationId' => $input['organizationId'] ?? null,
            'clientId' => $resolved['clientId'] ?? $input['clientId'] ?? null,
            'projectId' => $resolved['projectId'],
            'projectSourceId' => $resolved['projectSourceId'],
            'channel' => 'ecommerce',
            'event' => 'order.placed',
            'timestamp' => $input['timestamp'] ?? gmdate('c'),
            'icon' => 'shopping-cart',
            'properties' => [
                'orderId' => $input['orderId'],
                'orderTotal' => $input['orderTotal'] ?? $input['total'],
                'currency' => $input['currency'],
                'itemCount' => $input['itemCount'],
                'submittedAt' => $input['submittedAt'],
            ],
            'tags' => is_array($input['tags'] ?? null) ? $input['tags'] : [],
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
            'event' => 'item.purchased',
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
            'tags' => is_array($input['tags'] ?? null) ? $input['tags'] : [],
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
}
