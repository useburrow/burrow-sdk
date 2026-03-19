<?php

declare(strict_types=1);

namespace Burrow\Sdk\Events;

use Burrow\Sdk\Events\Exception\EventContractException;

final class CanonicalEventName
{
    private const SYSTEM_EVENTS = [
        'system.stack.snapshot',
        'system.heartbeat.ping',
        'system.plugin.updated',
        'system.cms.updated',
    ];

    private const ECOMMERCE_EVENTS = [
        'ecommerce.order.placed',
        'ecommerce.item.purchased',
        'ecommerce.cart.added',
        'ecommerce.cart.removed',
        'ecommerce.checkout.started',
        'ecommerce.checkout.abandoned',
        'ecommerce.cart.abandoned',
        'ecommerce.cart.recovered',
        'ecommerce.payment.failed',
        'ecommerce.order.cancelled',
        'ecommerce.order.fulfilled',
        'ecommerce.order.refunded',
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

        $prefix = $channelKey . '.';
        $isPrefixed = str_starts_with($rawEvent, $prefix);
        if ($strict && !$isPrefixed) {
            throw new EventContractException(
                errorCode: 'EVENT_NAME_PREFIX_REQUIRED',
                message: sprintf(
                    'Event name "%s" for channel "%s" must be prefixed as channel.entity.action.',
                    $event,
                    $channel
                ),
                remediation: sprintf('Use "%s%s".', $prefix, $rawEvent)
            );
        }

        $canonical = $isPrefixed ? $rawEvent : $prefix . $rawEvent;

        self::assertKnownCanonicalEvent($channelKey, $canonical);

        return $canonical;
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
