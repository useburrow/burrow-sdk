<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Contracts\OnboardingLinkResponse;
use PHPUnit\Framework\TestCase;

final class OnboardingLinkResponseTest extends TestCase
{
    public function testParsesCapabilitiesEchoIncludingFalsyFunnelFlag(): void
    {
        $response = OnboardingLinkResponse::fromResponseBody([
            'routing' => ['projectId' => 'prj_123'],
            'capabilities' => [
                'forms' => ['formie'],
                'ecommerce' => ['shopify'],
                'ecommerce_funnel' => false,
            ],
        ]);

        $this->assertSame([
            'forms' => ['formie'],
            'ecommerce' => ['shopify'],
            'ecommerce_funnel' => false,
        ], $response->capabilities);
        $this->assertFalse($response->capabilities['ecommerce_funnel']);
    }

    public function testCapabilitiesDefaultsToEmptyArrayWhenMissing(): void
    {
        $response = OnboardingLinkResponse::fromResponseBody([
            'routing' => ['projectId' => 'prj_123'],
        ]);

        $this->assertSame([], $response->capabilities);
    }

    public function testCapabilitiesDefaultsToEmptyArrayWhenNotAnArray(): void
    {
        $response = OnboardingLinkResponse::fromResponseBody([
            'capabilities' => 'invalid',
        ]);

        $this->assertSame([], $response->capabilities);
    }

    public function testConstructorDefaultsCapabilitiesForExistingCallers(): void
    {
        $response = new OnboardingLinkResponse(
            routing: [],
            ingestionKey: null,
            project: null
        );

        $this->assertSame([], $response->capabilities);
    }
}
