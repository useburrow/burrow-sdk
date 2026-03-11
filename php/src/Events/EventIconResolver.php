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
        'stack.snapshot' => 'layers',
        'heartbeat.ping' => 'heart',
        'ecommerce.order.placed' => 'shopping-cart',
        'ecommerce.order.cancelled' => 'circle-x',
        'ecommerce.order.fulfilled' => 'badge-check',
        'ecommerce.order.refunded' => 'rotate-ccw',
        'ecommerce.item.purchased' => 'package',
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
