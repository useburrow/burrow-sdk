<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Client\BurrowClient;
use Burrow\Sdk\Contracts\BackfillEventsRequest;
use Burrow\Sdk\Contracts\BackfillWindow;
use Burrow\Sdk\Contracts\FormsContractSubmissionRequest;
use Burrow\Sdk\Contracts\OnboardingLinkRequest;
use Burrow\Sdk\Events\CanonicalEnvelopeBuilders;
use Burrow\Sdk\Events\CanonicalEventName;
use Burrow\Sdk\Events\ChannelRoutingResolver;
use Burrow\Sdk\Events\ChannelRoutingState;
use Burrow\Sdk\Events\Exception\EventContractException;
use Burrow\Sdk\Transport\HttpResponse;
use Burrow\Sdk\Transport\HttpTransportInterface;
use PHPUnit\Framework\TestCase;

final class EventContractHardeningTest extends TestCase
{
    public function testNormalizesPrefixedNamesOutsideStrictMode(): void
    {
        $this->assertSame('stack.snapshot', CanonicalEventName::normalize('system', 'system.stack.snapshot'));
        $this->assertSame('order.placed', CanonicalEventName::normalize('ecommerce', 'ecommerce.order.placed'));
    }

    public function testRejectsPrefixedNamesInStrictMode(): void
    {
        $this->expectException(EventContractException::class);
        CanonicalEventName::normalize('system', 'system.stack.snapshot', true);
    }

    public function testResolvesRoutingByChannelAndErrorsWhenMissing(): void
    {
        $resolver = new ChannelRoutingResolver(new ChannelRoutingState(
            projectId: 'prj_123',
            projectSourceIds: ['system' => 'src_system_123', 'forms' => 'src_forms_123'],
            clientId: 'client_123'
        ));

        $routing = $resolver->getRoutingForChannel('system');
        $this->assertSame('prj_123', $routing['projectId']);
        $this->assertSame('src_system_123', $routing['projectSourceId']);

        $this->expectException(EventContractException::class);
        $resolver->getRoutingForChannel('ecommerce');
    }

    public function testBuildersProduceCanonicalSystemAndEcommerceShapes(): void
    {
        $resolver = new ChannelRoutingResolver(new ChannelRoutingState(
            projectId: 'prj_123',
            projectSourceIds: ['system' => 'src_system_123', 'ecommerce' => 'src_ecom_123'],
            clientId: 'client_123'
        ));

        $stack = CanonicalEnvelopeBuilders::buildSystemStackSnapshotEvent([
            'organizationId' => 'org_123',
            'cms' => ['name' => 'wordpress', 'version' => '6.7.1', 'latestVersion' => '6.8.0', 'updateAvailable' => true],
            'runtime' => ['php' => '8.2.18', 'database' => 'mysql:8.0'],
            'plugins' => [['handle' => 'woo', 'name' => 'WooCommerce', 'version' => '9.0.0', 'latest' => '9.1.0', 'updateAvailable' => true]],
            'updatesAvailable' => 1,
            'totalPlugins' => 1,
            'tags' => ['cmsVersion' => '6.7.1', 'phpVersion' => '8.2.18', 'hasUpdates' => 'true', 'updatesCount' => '1'],
        ], $resolver);
        $this->assertSame('system', $stack['channel']);
        $this->assertSame('stack.snapshot', $stack['event']);
        $this->assertSame('layers', $stack['icon']);
        $this->assertSame('src_system_123', $stack['projectSourceId']);

        $heartbeat = CanonicalEnvelopeBuilders::buildSystemHeartbeatEvent([
            'organizationId' => 'org_123',
            'responseMs' => 42,
        ], $resolver);
        $this->assertSame('heartbeat.ping', $heartbeat['event']);
        $this->assertSame('heart', $heartbeat['icon']);

        $order = CanonicalEnvelopeBuilders::buildEcommerceOrderPlacedEvent([
            'organizationId' => 'org_123',
            'orderId' => '1001',
            'orderTotal' => 120.50,
            'currency' => 'USD',
            'itemCount' => 2,
            'submittedAt' => '2026-03-09T00:00:00.000Z',
            'tags' => ['provider' => 'woocommerce', 'status' => 'paid'],
        ], $resolver);
        $this->assertSame('order.placed', $order['event']);
        $this->assertSame('shopping-cart', $order['icon']);
        $this->assertSame('src_ecom_123', $order['projectSourceId']);

        $item = CanonicalEnvelopeBuilders::buildEcommerceItemPurchasedEvent([
            'organizationId' => 'org_123',
            'orderId' => '1001',
            'productId' => 'sku_1',
            'productName' => 'Widget',
            'quantity' => 1,
            'unitPrice' => 19.99,
            'lineTotal' => 19.99,
            'currency' => 'USD',
            'submittedAt' => '2026-03-09T00:00:00.000Z',
            'tags' => ['provider' => 'woocommerce', 'productType' => 'simple'],
        ], $resolver);
        $this->assertSame('item.purchased', $item['event']);
        $this->assertSame('shopping-cart', $item['icon']);
    }

    public function testBackfillUsesCanonicalNamesAndChannelSourceIds(): void
    {
        $transport = new HardeningRecordingTransport([
            new HttpResponse(200, [
                'routing' => ['projectId' => 'prj_123'],
                'ingestionKey' => ['key' => 'burrow_prj', 'scope' => 'project', 'projectId' => 'prj_123'],
            ], '{"ok":true}'),
            new HttpResponse(200, ['projectSourceId' => 'src_forms_123', 'contractsVersion' => 'v1', 'contractMappings' => [], 'formsContracts' => []], '{"ok":true}'),
            new HttpResponse(207, ['accepted' => [], 'rejected' => []], '{"accepted":[],"rejected":[]}'),
        ]);

        $client = new BurrowClient('https://api.example.com', 'bootstrap_key', $transport);
        $client->link(new OnboardingLinkRequest(
            site: ['url' => 'https://site.test'],
            selection: ['organizationId' => 'org_123', 'projectId' => 'prj_123']
        ));
        $client->submitFormsContract(new FormsContractSubmissionRequest([
            'platform' => 'wordpress',
            'routing' => ['projectId' => 'prj_123'],
            'formsContracts' => [],
        ]));

        $resolver = new ChannelRoutingResolver(new ChannelRoutingState(
            projectId: 'prj_123',
            projectSourceIds: ['system' => 'src_system_123', 'ecommerce' => 'src_ecom_123'],
            clientId: 'client_123'
        ));
        $event = CanonicalEnvelopeBuilders::buildEcommerceOrderPlacedEvent([
            'organizationId' => 'org_123',
            'orderId' => '1001',
            'orderTotal' => 120.50,
            'currency' => 'USD',
            'itemCount' => 2,
            'submittedAt' => '2026-03-09T00:00:00.000Z',
            'tags' => ['provider' => 'woocommerce'],
        ], $resolver);

        $client->backfillEvents(new BackfillEventsRequest(
            events: [$event],
            backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z')
        ));

        self::assertIsArray($transport->lastPayload);
        self::assertArrayHasKey('events', $transport->lastPayload);
        $events = $transport->lastPayload['events'];
        self::assertIsArray($events);
        self::assertSame('order.placed', $events[0]['event'] ?? null);
        self::assertSame('src_ecom_123', $events[0]['projectSourceId'] ?? null);
    }
}

final class HardeningRecordingTransport implements HttpTransportInterface
{
    /** @var list<HttpResponse> */
    private array $responses;

    /** @var array<string,mixed> */
    public array $lastPayload = [];

    /**
     * @param list<HttpResponse> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function post(string $url, array $headers, array $payload): HttpResponse
    {
        $this->lastPayload = $payload;
        $response = array_shift($this->responses);
        if ($response === null) {
            throw new \RuntimeException('No response queued.');
        }

        return $response;
    }
}
