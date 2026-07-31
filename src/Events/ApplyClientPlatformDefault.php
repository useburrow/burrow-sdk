<?php

declare(strict_types=1);

namespace Burrow\Sdk\Events;

final class ApplyClientPlatformDefault
{
    /**
     * Sources that belong to a different CMS platform and should be cleared
     * so ingest can infer the correct default for the linked client platform.
     *
     * @var array<string, list<string>>
     */
    private const FOREIGN_SOURCES_BY_PLATFORM = [
        'craft' => ['wordpress-plugin', 'statamic-addon'],
        'wordpress' => ['craft-plugin', 'statamic-addon'],
        'statamic' => ['wordpress-plugin', 'craft-plugin'],
    ];

    /**
     * @param array<string,mixed> $event
     *
     * @return array<string,mixed>
     */
    public static function apply(array $event, ?string $clientPlatform): array
    {
        if ($clientPlatform === null || trim($clientPlatform) === '') {
            return $event;
        }

        if (EventSourceResolver::extractPlatformHint($event) !== null) {
            return $event;
        }

        $norm = strtolower(trim($clientPlatform));
        $foreignSources = self::FOREIGN_SOURCES_BY_PLATFORM[$norm] ?? null;
        if ($foreignSources === null) {
            return $event;
        }

        $out = $event;
        $sourceStr = isset($out['source']) && is_string($out['source']) ? trim($out['source']) : '';

        if ($sourceStr === '' || in_array($sourceStr, $foreignSources, true)) {
            unset($out['source']);
            $out['platform'] = $norm;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $event
     */
    public static function needsInferredSource(array $event): bool
    {
        if (!isset($event['source'])) {
            return true;
        }

        return !is_string($event['source']) || trim($event['source']) === '';
    }
}
