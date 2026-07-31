<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final class ReservedCanonicalKeys
{
    public const FEED_PREFIX = 'feed_';

    /**
     * Burrow ingestion reserved dimension keys (case-sensitive exact match).
     * Source: src/lib/events/ingestion.ts
     *
     * @var list<string>
     */
    public const ALL = [
        'organizationId',
        'clientId',
        'projectId',
        'integrationId',
        'projectSourceId',
        'clientSourceId',
        'channel',
        'event',
        'timestamp',
        'source',
        'schemaVersion',
        'isLifecycle',
        'entityType',
        'externalEntityId',
        'externalEventId',
        'state',
        'stateChangedAt',
    ];

    public static function isReserved(string $key): bool
    {
        $trimmed = trim($key);
        if ($trimmed === '') {
            return false;
        }

        if (str_starts_with($trimmed, self::FEED_PREFIX)) {
            return false;
        }

        return in_array($trimmed, self::ALL, true);
    }

    /**
     * Matches Burrow sanitizeIncomingDimensionKey(key).
     */
    public static function sanitizeIncomingDimensionKey(string $key): string
    {
        $trimmed = trim($key);
        if ($trimmed === '') {
            return '';
        }

        if (self::isReserved($trimmed)) {
            return self::FEED_PREFIX . $trimmed;
        }

        return $trimmed;
    }

    public static function sanitizeCanonicalKey(string $key): string
    {
        return self::sanitizeIncomingDimensionKey($key);
    }

    /**
     * @param array<string,mixed> $map
     * @return array<string,mixed>
     */
    public static function sanitizePropertyAndTagKeys(array $map): array
    {
        $sanitized = [];

        foreach ($map as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $sanitizedKey = self::sanitizeIncomingDimensionKey($key);
            if ($sanitizedKey === '') {
                continue;
            }

            $sanitized[$sanitizedKey] = $value;
        }

        return $sanitized;
    }
}
