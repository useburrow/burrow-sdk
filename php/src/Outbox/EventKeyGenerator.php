<?php

declare(strict_types=1);

namespace Burrow\Sdk\Outbox;

final class EventKeyGenerator
{
    /**
     * @param array<string,mixed> $event
     * @param array{
     *   provider?:string,
     *   source?:string,
     *   projectId?:string,
     *   entityIds?:array<string,string|int|float>,
     *   versionMarker?:string
     * } $context
     * @return array{eventKey:string,canonical:string}
     */
    public static function buildDeterministic(array $event, array $context = []): array
    {
        $channel = self::readString($event['channel'] ?? null) ?? 'unknown';
        $eventName = self::readString($event['event'] ?? null) ?? 'unknown';
        $provider = self::readString($context['provider'] ?? null)
            ?? self::readString($context['source'] ?? null)
            ?? self::readString($event['provider'] ?? null)
            ?? self::readString($event['source'] ?? null)
            ?? 'unknown';
        $projectId = self::readString($context['projectId'] ?? null)
            ?? self::readString($event['projectId'] ?? null)
            ?? 'unknown';
        $version = self::readString($context['versionMarker'] ?? null)
            ?? self::readString($event['timestamp'] ?? null)
            ?? self::readString($event['updatedAt'] ?? null)
            ?? self::readString($event['version'] ?? null)
            ?? 'unknown';

        $entityIds = [];
        foreach (['externalEventId', 'submissionId', 'orderId', 'lineItemId', 'pluginId', 'id'] as $key) {
            $value = $event[$key] ?? null;
            if (is_string($value) || is_int($value) || is_float($value)) {
                $entityIds[$key] = (string) $value;
            }
        }
        $contextEntityIds = $context['entityIds'] ?? [];
        if (is_array($contextEntityIds)) {
            foreach ($contextEntityIds as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                if (is_string($value) || is_int($value) || is_float($value)) {
                    $entityIds[$key] = (string) $value;
                }
            }
        }
        ksort($entityIds);

        $entityPairs = [];
        foreach ($entityIds as $key => $value) {
            $entityPairs[] = sprintf('%s=%s', $key, $value);
        }
        $canonical = implode('|', [
            sprintf('channel=%s', $channel),
            sprintf('event=%s', $eventName),
            sprintf('provider=%s', $provider),
            sprintf('projectId=%s', $projectId),
            sprintf('entityIds=%s', $entityPairs === [] ? 'none' : implode('&', $entityPairs)),
            sprintf('version=%s', $version),
        ]);

        return [
            'eventKey' => hash('sha256', $canonical),
            'canonical' => $canonical,
        ];
    }

    private static function readString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
