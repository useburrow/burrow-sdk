<?php

declare(strict_types=1);

namespace Burrow\Sdk\Events;

use Burrow\Sdk\Events\Exception\EventContractException;

final class CanonicalEventName
{
    private const SYSTEM_EVENTS = [
        'stack.snapshot',
        'heartbeat.ping',
        'plugin.updated',
        'cms.updated',
    ];

    private const ECOMMERCE_EVENTS = [
        'order.placed',
        'item.purchased',
        'order.fulfilled',
        'order.refunded',
    ];

    private const FORMS_EVENTS = [
        'forms.submission.received',
    ];

    public static function normalize(string $channel, string $event, bool $strict = false): string
    {
        $channelKey = strtolower(trim($channel));
        $rawEvent = strtolower(trim($event));

        if ($channelKey === '') {
            return $rawEvent;
        }

        if ($channelKey === 'forms' && $rawEvent === 'submission.received') {
            $rawEvent = 'forms.submission.received';
        }

        $prefix = $channelKey . '.';
        if (
            ($channelKey === 'system' || $channelKey === 'ecommerce')
            && str_starts_with($rawEvent, $prefix)
        ) {
            if ($strict) {
                throw new EventContractException(
                    errorCode: 'EVENT_NAME_PREFIX_NOT_ALLOWED',
                    message: sprintf(
                        'Event name "%s" for channel "%s" must be unprefixed.',
                        $event,
                        $channel
                    ),
                    remediation: sprintf('Use "%s" instead of "%s".', substr($rawEvent, strlen($prefix)), $rawEvent)
                );
            }
            $rawEvent = substr($rawEvent, strlen($prefix));
        }

        self::assertKnownCanonicalEvent($channelKey, $rawEvent);

        return $rawEvent;
    }

    private static function assertKnownCanonicalEvent(string $channel, string $event): void
    {
        $allowed = match ($channel) {
            'system' => self::SYSTEM_EVENTS,
            'ecommerce' => self::ECOMMERCE_EVENTS,
            'forms' => self::FORMS_EVENTS,
            default => null,
        };

        if ($allowed !== null && !in_array($event, $allowed, true)) {
            throw new EventContractException(
                errorCode: 'UNKNOWN_CANONICAL_EVENT',
                message: sprintf('Event "%s" is not recognized for channel "%s".', $event, $channel),
                remediation: 'Use an approved canonical event name for this channel.'
            );
        }
    }
}
