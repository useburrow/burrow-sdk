<?php

declare(strict_types=1);

namespace Burrow\Sdk\Events;

use InvalidArgumentException;

final class EventEnvelopeBuilder
{
    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    public static function build(array $event): array
    {
        $required = ['organizationId', 'clientId', 'channel', 'event', 'timestamp'];
        foreach ($required as $field) {
            if (!isset($event[$field]) || $event[$field] === '') {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }

        return [
            'organizationId' => (string) $event['organizationId'],
            'clientId' => (string) $event['clientId'],
            'projectId' => $event['projectId'] ?? null,
            'projectSourceId' => $event['projectSourceId'] ?? null,
            'channel' => (string) $event['channel'],
            'event' => (string) $event['event'],
            'timestamp' => (string) $event['timestamp'],
            'source' => isset($event['source']) ? (string) $event['source'] : null,
            'description' => isset($event['description']) ? (string) $event['description'] : null,
            'schemaVersion' => (string) ($event['schemaVersion'] ?? '1'),
            'properties' => is_array($event['properties'] ?? null) ? $event['properties'] : [],
            'tags' => is_array($event['tags'] ?? null) ? $event['tags'] : [],
        ];
    }
}
