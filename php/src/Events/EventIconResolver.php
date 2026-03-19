<?php

declare(strict_types=1);

namespace Burrow\Sdk\Events;

final class EventIconResolver
{
    /**
     * @var array<string,string>
     */
    private const EVENT_ICON_MAP = [
        'forms.submission.received' => 'file-signature',
        'order.placed' => 'shopping-cart',
        'order.cancelled' => 'circle-x',
        'order.fulfilled' => 'badge-check',
        'order.refunded' => 'rotate-ccw',
        'item.purchased' => 'package',
        'ecommerce.cart.added' => 'package-plus',
        'ecommerce.cart.removed' => 'package-minus',
        'ecommerce.checkout.started' => 'credit-card',
        'ecommerce.checkout.abandoned' => 'hourglass',
        'ecommerce.cart.abandoned' => 'clock-fading',
        'ecommerce.cart.recovered' => 'rotate-ccw',
        'ecommerce.payment.failed' => 'circle-alert',
        'stack.snapshot' => 'layers',
        'heartbeat.ping' => 'heart',
        'ecommerce.order.placed' => 'shopping-cart',
        'ecommerce.order.cancelled' => 'circle-x',
        'ecommerce.order.fulfilled' => 'badge-check',
        'ecommerce.order.refunded' => 'rotate-ccw',
        'ecommerce.item.purchased' => 'package',
        'cart.item.added' => 'package-plus',
        'cart.item.removed' => 'package-minus',
        'checkout.started' => 'credit-card',
        'checkout.abandoned' => 'hourglass',
        'cart.abandoned' => 'clock-fading',
        'cart.recovered' => 'rotate-ccw',
        'payment.failed' => 'circle-alert',
        'system.stack.snapshot' => 'layers',
        'system.heartbeat.ping' => 'heart',
        'code.commit.pushed' => 'git-commit-horizontal',
        'analytics.stats.daily' => 'chart-column',
        'monitoring.incident.started' => 'triangle-alert',
        'backups.job.completed' => 'database-backup',
        'invoicing.invoice.synced' => 'receipt-text',
    ];

    /**
     * @var array<string,string>
     */
    private const CHANNEL_ICON_DEFAULTS = [
        'forms' => 'file-signature',
        'ecommerce' => 'shopping-cart',
        'system' => 'layers',
        'code' => 'git-commit-horizontal',
        'analytics' => 'chart-column',
        'monitoring' => 'triangle-alert',
        'backups' => 'database-backup',
        'invoicing' => 'receipt-text',
    ];

    public static function resolveIconForEvent(string $channel, string $event): ?string
    {
        $eventKey = strtolower(trim($event));
        if (isset(self::EVENT_ICON_MAP[$eventKey])) {
            return self::EVENT_ICON_MAP[$eventKey];
        }

        $channelKey = strtolower(trim($channel));
        return self::CHANNEL_ICON_DEFAULTS[$channelKey] ?? null;
    }
}
