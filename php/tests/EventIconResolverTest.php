<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Events\EventIconResolver;
use PHPUnit\Framework\TestCase;

final class EventIconResolverTest extends TestCase
{
    public function testReturnsCanonicalIconForKnownEvents(): void
    {
        $this->assertSame('file-signature', EventIconResolver::resolveIconForEvent('forms', 'forms.submission.received'));
        $this->assertSame('heart', EventIconResolver::resolveIconForEvent('system', 'heartbeat.ping'));
        $this->assertSame('shopping-cart', EventIconResolver::resolveIconForEvent('ecommerce', 'order.placed'));
        $this->assertSame('circle-x', EventIconResolver::resolveIconForEvent('ecommerce', 'order.cancelled'));
        $this->assertSame('badge-check', EventIconResolver::resolveIconForEvent('ecommerce', 'order.fulfilled'));
        $this->assertSame('rotate-ccw', EventIconResolver::resolveIconForEvent('ecommerce', 'order.refunded'));
        $this->assertSame('package', EventIconResolver::resolveIconForEvent('ecommerce', 'item.purchased'));
        $this->assertSame('package-plus', EventIconResolver::resolveIconForEvent('ecommerce', 'cart.item.added'));
        $this->assertSame('package-minus', EventIconResolver::resolveIconForEvent('ecommerce', 'cart.item.removed'));
        $this->assertSame('credit-card', EventIconResolver::resolveIconForEvent('ecommerce', 'checkout.started'));
        $this->assertSame('hourglass', EventIconResolver::resolveIconForEvent('ecommerce', 'checkout.abandoned'));
        $this->assertSame('rotate-ccw', EventIconResolver::resolveIconForEvent('ecommerce', 'cart.recovered'));
    }

    public function testFallsBackToChannelDefaultForUnknownEvent(): void
    {
        $this->assertSame('chart-column', EventIconResolver::resolveIconForEvent('analytics', 'analytics.unknown.event'));
    }

    public function testReturnsNullForUnknownChannelAndEvent(): void
    {
        $this->assertNull(EventIconResolver::resolveIconForEvent('unknown-channel', 'unknown.event'));
    }
}
