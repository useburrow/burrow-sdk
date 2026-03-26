<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Events\EventSourceResolver;
use PHPUnit\Framework\TestCase;

final class EventSourceResolverTest extends TestCase
{
    public function testMapsPlatformToDefaultCmsPluginSource(): void
    {
        $this->assertSame('craft-plugin', EventSourceResolver::getDefaultEventSource('craft'));
        $this->assertSame('craft-plugin', EventSourceResolver::getDefaultEventSource('Craft'));
        $this->assertSame('wordpress-plugin', EventSourceResolver::getDefaultEventSource('wordpress'));
        $this->assertSame('wordpress-plugin', EventSourceResolver::getDefaultEventSource(null));
        $this->assertSame('wordpress-plugin', EventSourceResolver::getDefaultEventSource(''));
    }

    public function testResolvesFormsProvidersToCanonicalSlugs(): void
    {
        $this->assertSame('gravity-forms', EventSourceResolver::resolveSourceForEvent([
            'channel' => 'forms',
            'properties' => ['provider' => 'gravityforms'],
        ]));

        $this->assertSame('fluent-forms', EventSourceResolver::resolveSourceForEvent([
            'channel' => 'forms',
            'properties' => ['provider' => 'fluent-forms'],
        ]));
    }

    public function testResolvesEcommerceProvidersToCanonicalSlugs(): void
    {
        $this->assertSame('woocommerce', EventSourceResolver::resolveSourceForEvent([
            'channel' => 'ecommerce',
            'tags' => ['provider' => 'woocommerce'],
        ]));

        $this->assertSame('craft-commerce', EventSourceResolver::resolveSourceForEvent([
            'channel' => 'ecommerce',
            'properties' => ['provider' => 'craftcommerce'],
        ]));
    }

    public function testFallsBackToPlatformPluginWhenProviderUnknown(): void
    {
        $this->assertSame('wordpress-plugin', EventSourceResolver::resolveSourceForEvent([
            'channel' => 'forms',
            'properties' => ['provider' => 'unknown-form-plugin'],
            'platform' => 'wordpress',
        ]));

        $this->assertSame('craft-plugin', EventSourceResolver::resolveSourceForEvent([
            'channel' => 'forms',
            'properties' => ['provider' => 'unknown-form-plugin'],
            'platform' => 'craft',
        ]));
    }
}
