<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Events\EventSourceResolver;
use PHPUnit\Framework\TestCase;

final class EventSourceResolverTest extends TestCase
{
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
