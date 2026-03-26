<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Client\BurrowClient;
use Burrow\Sdk\Client\Exception\SdkApiException;
use Burrow\Sdk\Contracts\BackfillEventsRequest;
use Burrow\Sdk\Contracts\BackfillWindow;
use Burrow\Sdk\Contracts\FormsContractSubmissionRequest;
use Burrow\Sdk\Contracts\OnboardingDiscoveryRequest;
use Burrow\Sdk\Contracts\OnboardingLinkRequest;
use Burrow\Sdk\Transport\HttpResponse;
use Burrow\Sdk\Transport\HttpTransportInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BurrowClientTest extends TestCase
{
    public function testDiscoverUsesExpectedPathHeadersAndPayload(): void
    {
        $transport = new RecordingTransport(new HttpResponse(200, ['ok' => true], '{"ok":true}'));
        $client = new BurrowClient('https://api.example.com', 'secret_key', $transport);

        $request = new OnboardingDiscoveryRequest(
            site: ['url' => 'https://site.test'],
            capabilities: ['forms' => ['freeform']]
        );

        $response = $client->discover($request);

        $this->assertSame(200, $response->status);
        $this->assertSame('https://api.example.com/api/v1/plugin-onboarding/discover', $transport->lastUrl);
        $this->assertSame(['x-api-key' => 'secret_key'], $transport->lastHeaders);
        $this->assertSame($request->toArray(), $transport->lastPayload);
    }

    public function testSupportsAllEndpointMethods(): void
    {
        $transport = new RecordingTransport(new HttpResponse(207, ['partial' => true], '{"partial":true}'));
        $client = new BurrowClient('https://api.example.com/', 'secret_key', $transport);

        $client->link(new OnboardingLinkRequest(
            site: ['url' => 'https://site.test'],
            selection: ['organizationId' => 'org_123']
        ));
        $this->assertSame('https://api.example.com/api/v1/plugin-onboarding/link', $transport->lastUrl);

        $client->submitFormsContract(new FormsContractSubmissionRequest(['formsContracts' => []]));
        $this->assertSame('https://api.example.com/api/v1/plugin-onboarding/forms/contracts', $transport->lastUrl);

        $client->fetchFormsContracts('prj_123', 'craft');
        $this->assertSame('https://api.example.com/api/v1/plugin-onboarding/forms/contracts/fetch', $transport->lastUrl);

        $client->publishEvent([
            'organizationId' => 'org_123',
            'clientId' => 'client_123',
            'projectId' => 'prj_123',
            'projectSourceId' => 'src_forms_123',
            'channel' => 'forms',
            'event' => 'forms.submission.received',
            'timestamp' => '2026-03-01T00:00:00.000Z',
        ]);
        $this->assertSame('https://api.example.com/api/v1/events', $transport->lastUrl);

        $client->backfillEvents(new BackfillEventsRequest(
            events: [[
                'organizationId' => 'org_123',
                'clientId' => 'client_123',
                'projectId' => 'prj_123',
                'projectSourceId' => 'src_forms_123',
                'channel' => 'forms',
                'event' => 'forms.submission.received',
                'timestamp' => '2026-03-01T12:00:00.000Z',
            ]],
            backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z')
        ));
        $this->assertSame('https://api.example.com/api/v1/plugin-backfill/events', $transport->lastUrl);
    }

    public function testLinkSupportsOptionalPlatformAndCapabilities(): void
    {
        $transport = new RecordingTransport(new HttpResponse(200, ['ok' => true], '{"ok":true}'));
        $client = new BurrowClient('https://api.example.com', 'secret_key', $transport);

        $client->link(new OnboardingLinkRequest(
            site: ['url' => 'https://site.test'],
            selection: ['organizationId' => 'org_123', 'projectId' => 'prj_123'],
            platform: 'wordpress',
            capabilities: [
                'forms' => ['gravity-forms'],
                'ecommerce' => ['woocommerce'],
                'system' => true,
            ]
        ));

        $this->assertSame('https://api.example.com/api/v1/plugin-onboarding/link', $transport->lastUrl);
        $this->assertSame('wordpress', $transport->lastPayload['platform'] ?? null);
        $this->assertSame(['gravity-forms'], $transport->lastPayload['capabilities']['forms'] ?? null);
    }

    public function testThrowsOnUnexpectedStatusCode(): void
    {
        $transport = new RecordingTransport(new HttpResponse(400, ['error' => 'bad request'], '{"error":"bad request"}'));
        $client = new BurrowClient('https://api.example.com', 'secret_key', $transport);

        $this->expectException(SdkApiException::class);
        $client->publishEvent([
            'organizationId' => 'org_123',
            'clientId' => 'client_123',
            'projectId' => 'prj_123',
            'projectSourceId' => 'src_forms_123',
            'channel' => 'forms',
            'event' => 'forms.submission.received',
            'timestamp' => '2026-03-01T00:00:00.000Z',
        ]);
    }

    public function testParsesLinkResponseDeepLinkAndUsesScopedIngestionKeyForEvents(): void
    {
        $transport = new QueueRecordingTransport([
            new HttpResponse(200, [
                'routing' => ['projectId' => 'prj_123'],
                'ingestionKey' => [
                    'key' => 'burrow_prj_key_abc',
                    'keyPrefix' => 'burrow_prj',
                    'scope' => 'project',
                    'projectId' => 'prj_123',
                ],
                'project' => [
                    'id' => 'prj_123',
                    'name' => 'Anysizebasket',
                    'slug' => 'anysizebasket-com',
                    'clientId' => 'cli_123',
                    'clientName' => 'Three M Tool',
                    'clientSlug' => 'three-m-tool',
                    'burrowProjectPath' => '/clients/three-m-tool/projects/anysizebasket-com',
                    'burrowProjectUrl' => 'https://app.useburrow.com/clients/three-m-tool/projects/anysizebasket-com',
                ],
            ], '{"ok":true}'),
            new HttpResponse(200, ['ok' => true], '{"ok":true}'),
        ]);
        $client = new BurrowClient('https://api.example.com', 'bootstrap_key', $transport);

        $link = $client->link(new OnboardingLinkRequest(
            site: ['url' => 'https://site.test'],
            selection: ['organizationId' => 'org_123', 'projectId' => 'prj_123']
        ));

        $this->assertNotNull($link->ingestionKey);
        $this->assertSame('project', $link->ingestionKey?->scope);
        $this->assertSame('prj_123', $link->ingestionKey?->projectId);
        $this->assertNotNull($link->project);
        $this->assertSame('/clients/three-m-tool/projects/anysizebasket-com', $link->project?->burrowProjectPath);

        $deepLink = $client->getLinkedProjectDeepLink();
        $this->assertNotNull($deepLink);
        $this->assertSame('/clients/three-m-tool/projects/anysizebasket-com', $deepLink?->path);
        $this->assertSame(
            'https://app.useburrow.com/clients/three-m-tool/projects/anysizebasket-com',
            $deepLink?->url
        );

        $client->publishEvent([
            'projectId' => 'prj_123',
            'organizationId' => 'org_123',
            'clientId' => 'client_123',
            'projectSourceId' => 'src_forms_123',
            'channel' => 'forms',
            'event' => 'forms.submission.received',
            'timestamp' => '2026-03-01T00:00:00.000Z',
        ]);

        $this->assertSame(['x-api-key' => 'burrow_prj_key_abc'], $transport->lastHeaders);
    }

    public function testRequiresProjectIdForScopedKeyPublish(): void
    {
        $transport = new RecordingTransport(new HttpResponse(200, [
            'routing' => ['projectId' => 'prj_123'],
            'ingestionKey' => [
                'key' => 'burrow_prj_key_abc',
                'scope' => 'project',
                'projectId' => 'prj_123',
            ],
        ], '{"ok":true}'));
        $client = new BurrowClient('https://api.example.com', 'bootstrap_key', $transport);
        $client->link(new OnboardingLinkRequest(
            site: ['url' => 'https://site.test'],
            selection: ['organizationId' => 'org_123', 'projectId' => 'prj_123']
        ));

        $this->expectException(InvalidArgumentException::class);
        $client->publishEvent(['event' => 'forms.submission.received']);
    }

    public function testRejectsPublishWhenProjectIdDoesNotMatchScopedKey(): void
    {
        $transport = new RecordingTransport(new HttpResponse(200, [
            'routing' => ['projectId' => 'prj_123'],
            'ingestionKey' => [
                'key' => 'burrow_prj_key_abc',
                'scope' => 'project',
                'projectId' => 'prj_123',
            ],
        ], '{"ok":true}'));
        $client = new BurrowClient('https://api.example.com', 'bootstrap_key', $transport);
        $client->link(new OnboardingLinkRequest(
            site: ['url' => 'https://site.test'],
            selection: ['organizationId' => 'org_123', 'projectId' => 'prj_123']
        ));

        $this->expectException(InvalidArgumentException::class);
        $client->publishEvent([
            'projectId' => 'prj_999',
            'organizationId' => 'org_123',
            'clientId' => 'client_123',
            'projectSourceId' => 'src_forms_123',
            'channel' => 'forms',
            'event' => 'forms.submission.received',
            'timestamp' => '2026-03-01T00:00:00.000Z',
        ]);
    }

    public function testRejectsFormsFetchWhenScopedProjectDoesNotMatch(): void
    {
        $transport = new RecordingTransport(new HttpResponse(200, [
            'routing' => ['projectId' => 'prj_123'],
            'ingestionKey' => [
                'key' => 'burrow_prj_key_abc',
                'scope' => 'project',
                'projectId' => 'prj_123',
            ],
        ], '{"ok":true}'));
        $client = new BurrowClient('https://api.example.com', 'bootstrap_key', $transport);
        $client->link(new OnboardingLinkRequest(
            site: ['url' => 'https://site.test'],
            selection: ['organizationId' => 'org_123', 'projectId' => 'prj_123']
        ));

        $this->expectException(InvalidArgumentException::class);
        $client->fetchFormsContracts('prj_999', 'wordpress');
    }

    public function testRejectsFormsSubmitWhenScopedProjectDoesNotMatch(): void
    {
        $transport = new RecordingTransport(new HttpResponse(200, [
            'routing' => ['projectId' => 'prj_123'],
            'ingestionKey' => [
                'key' => 'burrow_prj_key_abc',
                'scope' => 'project',
                'projectId' => 'prj_123',
            ],
        ], '{"ok":true}'));
        $client = new BurrowClient('https://api.example.com', 'bootstrap_key', $transport);
        $client->link(new OnboardingLinkRequest(
            site: ['url' => 'https://site.test'],
            selection: ['organizationId' => 'org_123', 'projectId' => 'prj_123']
        ));

        $this->expectException(InvalidArgumentException::class);
        $client->submitFormsContract(new FormsContractSubmissionRequest([
            'platform' => 'wordpress',
            'routing' => ['projectId' => 'prj_999'],
            'formsContracts' => [],
        ]));
    }

    public function testCraftPlatformFromLinkSetsCraftPluginSourceOnPublish(): void
    {
        $transport = new QueueRecordingTransport([
            new HttpResponse(200, [
                'routing' => [],
                'ingestionKey' => [
                    'key' => 'ingest_key',
                    'keyPrefix' => 'burrow',
                    'scope' => 'organization',
                    'projectId' => null,
                ],
            ], '{}'),
            new HttpResponse(200, ['ok' => true], '{}'),
        ]);
        $client = new BurrowClient('https://api.example.com', 'bootstrap_key', $transport);

        $client->link(new OnboardingLinkRequest(
            site: ['url' => 'https://craft.test'],
            selection: ['organizationId' => 'org_123'],
            platform: 'craft'
        ));

        $this->assertSame('craft', $client->getState()->platform);

        $client->publishEvent([
            'organizationId' => 'org_123',
            'clientId' => 'cli_123',
            'projectId' => 'prj_123',
            'projectSourceId' => 'src_sys_123',
            'channel' => 'system',
            'event' => 'system.heartbeat.ping',
            'timestamp' => '2026-03-01T12:00:00.000Z',
            'properties' => ['responseMs' => 12],
            'tags' => [],
        ]);

        $this->assertSame('craft-plugin', $transport->lastPayload['source'] ?? null);
    }

    public function testWordPressPlatformFromLinkSetsWordPressPluginSourceOnPublish(): void
    {
        $transport = new QueueRecordingTransport([
            new HttpResponse(200, [
                'routing' => [],
                'ingestionKey' => [
                    'key' => 'ingest_key',
                    'keyPrefix' => 'burrow',
                    'scope' => 'organization',
                    'projectId' => null,
                ],
            ], '{}'),
            new HttpResponse(200, ['ok' => true], '{}'),
        ]);
        $client = new BurrowClient('https://api.example.com', 'bootstrap_key', $transport);

        $client->link(new OnboardingLinkRequest(
            site: ['url' => 'https://wp.test'],
            selection: ['organizationId' => 'org_123'],
            platform: 'wordpress'
        ));

        $client->publishEvent([
            'organizationId' => 'org_123',
            'clientId' => 'cli_123',
            'projectId' => 'prj_123',
            'projectSourceId' => 'src_sys_123',
            'channel' => 'system',
            'event' => 'system.heartbeat.ping',
            'timestamp' => '2026-03-01T12:00:00.000Z',
            'properties' => ['responseMs' => 12],
            'tags' => [],
        ]);

        $this->assertSame('wordpress-plugin', $transport->lastPayload['source'] ?? null);
    }
}

final class RecordingTransport implements HttpTransportInterface
{
    /** @var array<string, string> */
    public array $lastHeaders = [];

    /** @var array<string, mixed> */
    public array $lastPayload = [];

    public string $lastUrl = '';

    public function __construct(private readonly HttpResponse $response)
    {
    }

    public function post(string $url, array $headers, array $payload): HttpResponse
    {
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->lastPayload = $payload;

        return $this->response;
    }
}

final class QueueRecordingTransport implements HttpTransportInterface
{
    /** @var list<HttpResponse> */
    private array $responses;

    /** @var array<string, string> */
    public array $lastHeaders = [];

    /** @var array<string, mixed> */
    public array $lastPayload = [];

    public string $lastUrl = '';

    /**
     * @param list<HttpResponse> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function post(string $url, array $headers, array $payload): HttpResponse
    {
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->lastPayload = $payload;

        $response = array_shift($this->responses);
        if ($response === null) {
            throw new \RuntimeException('No response left in queue.');
        }

        return $response;
    }
}
