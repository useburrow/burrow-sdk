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
        $this->assertSame('system.stack.snapshot', CanonicalEventName::normalize('system', 'system.stack.snapshot'));
        $this->assertSame('ecommerce.order.placed', CanonicalEventName::normalize('ecommerce', 'ecommerce.order.placed'));
        $this->assertSame('ecommerce.order.placed', CanonicalEventName::normalize('ecommerce', 'order.placed'));
    }

    public function testRejectsPrefixedNamesInStrictMode(): void
    {
        $this->expectException(EventContractException::class);
        CanonicalEventName::normalize('system', 'stack.snapshot', true);
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
        $this->assertSame('system.stack.snapshot', $stack['event']);
        $this->assertSame('layers', $stack['icon']);
        $this->assertSame('src_system_123', $stack['projectSourceId']);

        $heartbeat = CanonicalEnvelopeBuilders::buildSystemHeartbeatEvent([
            'organizationId' => 'org_123',
            'responseMs' => 42,
        ], $resolver);
        $this->assertSame('system.heartbeat.ping', $heartbeat['event']);
        $this->assertSame('heart', $heartbeat['icon']);

        $order = CanonicalEnvelopeBuilders::buildEcommerceOrderPlacedEvent([
            'organizationId' => 'org_123',
            'orderId' => '1001',
            'externalEntityId' => 'woo:1001',
            'orderTotal' => 120.50,
            'currency' => 'USD',
            'itemCount' => 2,
            'submittedAt' => '2026-03-09T00:00:00.000Z',
            'tax' => 10.25,
            'subtotal' => 110.25,
            'customerToken' => 'cust_tok_1',
            'isGuest' => 'false',
            'orderSequence' => '3',
            'isNewCustomer' => 'false',
            'paymentMethod' => 'stripe',
            'shippingCountry' => 'US',
            'shippingRegion' => 'CA',
            'shippingMethod' => 'express',
            'tags' => ['provider' => 'woocommerce', 'status' => 'paid', 'couponCode' => 'SPRING25'],
        ], $resolver);
        $this->assertSame('ecommerce.order.placed', $order['event']);
        $this->assertSame('shopping-cart', $order['icon']);
        $this->assertSame('src_ecom_123', $order['projectSourceId']);
        $this->assertTrue($order['isLifecycle']);
        $this->assertSame('order', $order['entityType']);
        $this->assertSame('woo:1001', $order['externalEntityId']);
        $this->assertSame('placed', $order['state']);
        $this->assertSame(10.25, $order['properties']['tax']);
        $this->assertSame(110.25, $order['properties']['subtotal']);
        $this->assertSame('cust_tok_1', $order['tags']['customerToken']);
        $this->assertSame('SPRING25', $order['tags']['couponCode']);

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
            'customerToken' => 'cust_tok_1',
            'tags' => ['provider' => 'woocommerce', 'productType' => 'simple'],
        ], $resolver);
        $this->assertSame('ecommerce.item.purchased', $item['event']);
        $this->assertSame('shopping-cart', $item['icon']);
        $this->assertSame('cust_tok_1', $item['tags']['customerToken']);

        $fulfilled = CanonicalEnvelopeBuilders::buildEcommerceOrderFulfilledEvent([
            'organizationId' => 'org_123',
            'orderId' => '1001',
            'externalEntityId' => 'woo:1001',
            'orderTotal' => 120.50,
            'currency' => 'USD',
            'customerToken' => 'cust_tok_1',
            'tags' => ['provider' => 'woocommerce'],
        ], $resolver);
        $this->assertSame('ecommerce.order.fulfilled', $fulfilled['event']);
        $this->assertTrue($fulfilled['isLifecycle']);
        $this->assertSame('order', $fulfilled['entityType']);
        $this->assertSame('woo:1001', $fulfilled['externalEntityId']);
        $this->assertSame('fulfilled', $fulfilled['state']);
        $this->assertSame('cust_tok_1', $fulfilled['tags']['customerToken']);

        $refunded = CanonicalEnvelopeBuilders::buildEcommerceOrderRefundedEvent([
            'organizationId' => 'org_123',
            'orderId' => '1001',
            'externalEntityId' => 'woo:1001',
            'orderTotal' => 120.50,
            'currency' => 'USD',
            'customerToken' => 'cust_tok_1',
            'tags' => ['provider' => 'woocommerce'],
        ], $resolver);
        $this->assertSame('ecommerce.order.refunded', $refunded['event']);
        $this->assertSame('refunded', $refunded['state']);

        $cancelled = CanonicalEnvelopeBuilders::buildEcommerceOrderCancelledEvent([
            'organizationId' => 'org_123',
            'orderId' => '1001',
            'externalEntityId' => 'woo:1001',
            'orderTotal' => 120.50,
            'currency' => 'USD',
            'customerToken' => 'cust_tok_1',
            'tags' => ['provider' => 'woocommerce'],
        ], $resolver);
        $this->assertSame('ecommerce.order.cancelled', $cancelled['event']);
        $this->assertSame('cancelled', $cancelled['state']);

        $withoutCoupon = CanonicalEnvelopeBuilders::buildEcommerceOrderPlacedEvent([
            'organizationId' => 'org_123',
            'orderId' => '1002',
            'orderTotal' => 80.00,
            'currency' => 'USD',
            'itemCount' => 1,
            'submittedAt' => '2026-03-10T00:00:00.000Z',
            'tags' => ['provider' => 'woocommerce'],
        ], $resolver);
        $this->assertArrayNotHasKey('couponCode', $withoutCoupon['tags']);

        $cartAdded = CanonicalEnvelopeBuilders::buildEcommerceCartItemAddedEvent([
            'organizationId' => 'org_123',
            'productId' => 'sku_1',
            'productName' => 'Widget',
            'variantName' => 'Blue / L',
            'quantity' => 1,
            'unitPrice' => 19.99,
            'lineTotal' => 19.99,
            'currency' => 'USD',
            'cartTotal' => 120.50,
            'cartItemCount' => 3,
            'customerToken' => 'cust_tok_1',
            'tags' => ['provider' => 'woocommerce', 'category' => 'apparel'],
        ], $resolver);
        $this->assertSame('ecommerce.cart.added', $cartAdded['event']);
        $this->assertSame(19.99, $cartAdded['properties']['unitPrice']);
        $this->assertSame('sku_1', $cartAdded['tags']['productId']);

        $cartRemoved = CanonicalEnvelopeBuilders::buildEcommerceCartItemRemovedEvent([
            'organizationId' => 'org_123',
            'productId' => 'sku_1',
            'productName' => 'Widget',
            'quantity' => 1,
            'currency' => 'USD',
            'cartTotal' => 100.50,
            'cartItemCount' => 2,
            'tags' => ['provider' => 'woocommerce'],
        ], $resolver);
        $this->assertSame('ecommerce.cart.removed', $cartRemoved['event']);
        $this->assertArrayNotHasKey('unitPrice', $cartRemoved['properties']);
        $this->assertArrayNotHasKey('lineTotal', $cartRemoved['properties']);

        $checkoutStarted = CanonicalEnvelopeBuilders::buildEcommerceCheckoutStartedEvent([
            'organizationId' => 'org_123',
            'cartTotal' => 100.50,
            'cartItemCount' => 2,
            'currency' => 'USD',
            'isGuest' => 'true',
            'tags' => ['provider' => 'woocommerce'],
        ], $resolver);
        $this->assertSame('ecommerce.checkout.started', $checkoutStarted['event']);
        $this->assertSame('true', $checkoutStarted['tags']['isGuest']);

        $checkoutAbandoned = CanonicalEnvelopeBuilders::buildEcommerceCheckoutAbandonedEvent([
            'organizationId' => 'org_123',
            'externalEntityId' => 'wc_session_abc123',
            'cartTotal' => 100.50,
            'cartItemCount' => 2,
            'currency' => 'USD',
            'minutesSinceCheckout' => 45,
            'tags' => ['provider' => 'woocommerce'],
        ], $resolver);
        $this->assertSame('ecommerce.checkout.abandoned', $checkoutAbandoned['event']);
        $this->assertTrue($checkoutAbandoned['isLifecycle']);
        $this->assertSame('checkout', $checkoutAbandoned['entityType']);
        $this->assertSame('abandoned', $checkoutAbandoned['state']);
        $this->assertSame('wc_session_abc123', $checkoutAbandoned['externalEntityId']);

        $cartRecovered = CanonicalEnvelopeBuilders::buildEcommerceCartRecoveredEvent([
            'organizationId' => 'org_123',
            'orderId' => '1003',
            'orderTotal' => 100.50,
            'originalCartTotal' => 90.25,
            'currency' => 'USD',
            'minutesSinceAbandonment' => 11,
            'tags' => ['provider' => 'woocommerce'],
        ], $resolver);
        $this->assertSame('ecommerce.cart.recovered', $cartRecovered['event']);
        $this->assertSame(11, $cartRecovered['properties']['minutesSinceAbandonment']);
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
        self::assertSame('ecommerce.order.placed', $events[0]['event'] ?? null);
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
