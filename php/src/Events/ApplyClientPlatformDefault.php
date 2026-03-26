<?php

declare(strict_types=1);

namespace Burrow\Sdk\Events;

final class ApplyClientPlatformDefault
{
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
        if ($norm !== 'craft' && $norm !== 'wordpress') {
            return $event;
        }

        $out = $event;
        $sourceStr = isset($out['source']) && is_string($out['source']) ? trim($out['source']) : '';

        if ($norm === 'craft' && ($sourceStr === '' || $sourceStr === 'wordpress-plugin')) {
            unset($out['source']);
            $out['platform'] = 'craft';
        } elseif ($norm === 'wordpress' && ($sourceStr === '' || $sourceStr === 'craft-plugin')) {
            unset($out['source']);
            $out['platform'] = 'wordpress';
        } elseif ($sourceStr === '') {
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
