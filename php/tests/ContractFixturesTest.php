<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Contracts\FormsContractSubmissionRequest;
use Burrow\Sdk\Contracts\OnboardingLinkResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContractFixturesTest extends TestCase
{
    /** @var list<string> */
    private const REQUIRED_EVENT_KEYS = [
        'organizationId',
        'clientId',
        'projectId',
        'projectSourceId',
        'channel',
        'event',
        'timestamp',
        'source',
        'description',
        'schemaVersion',
        'properties',
        'tags',
    ];

    public function testFormsContractSubmissionFixtureCanBeSubmitted(): void
    {
        $fixturePath = self::specContractsDir() . '/forms-contracts.request.json';
        $contents = file_get_contents($fixturePath);
        self::assertNotFalse($contents);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded);

        $request = new FormsContractSubmissionRequest($decoded);
        $this->assertSame($decoded, $request->toArray());
    }

    public function testOnboardingLinkResponseFixtureRoundTripsCapabilities(): void
    {
        $fixturePath = self::specContractsDir() . '/onboarding-link.response.json';
        $contents = file_get_contents($fixturePath);
        self::assertNotFalse($contents);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded);

        $response = OnboardingLinkResponse::fromResponseBody($decoded);
        $this->assertSame($decoded['routing'], $response->routing);
        $this->assertNotNull($response->ingestionKey);
        $this->assertNotNull($response->project);
        $this->assertSame($decoded['capabilities'], $response->capabilities);
        $this->assertFalse($response->capabilities['ecommerce_funnel']);
    }

    public function testReservedCanonicalKeysFixtureSanitizesContractPost(): void
    {
        $fixturePath = self::specContractsDir() . '/forms-contract-reserved-canonical-keys.request.json';
        $contents = file_get_contents($fixturePath);
        self::assertNotFalse($contents);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded);

        $request = new FormsContractSubmissionRequest($decoded);
        $payload = $request->toArray();
        $mappings = $payload['formsContracts'][0]['fieldMappings'];

        $this->assertSame('feed_channel', $mappings[0]['canonicalKey']);
        $this->assertSame('feed_source', $mappings[1]['canonicalKey']);
        $this->assertSame('feed_customField', $mappings[2]['canonicalKey']);
        $this->assertSame('submissionId', $mappings[3]['canonicalKey']);
        $this->assertNotEmpty($request->warnings());
    }

    #[DataProvider('canonicalEventFixtureProvider')]
    public function testCanonicalEventFixturesHaveRequiredEnvelopeShape(string $fixtureName, string $expectedEvent): void
    {
        $fixturePath = self::specContractsDir() . '/' . $fixtureName;
        $contents = file_get_contents($fixturePath);
        self::assertNotFalse($contents, sprintf('Missing fixture %s', $fixtureName));

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded);

        foreach (self::REQUIRED_EVENT_KEYS as $key) {
            $this->assertArrayHasKey($key, $decoded, sprintf('Fixture %s is missing key %s', $fixtureName, $key));
        }

        $this->assertSame($expectedEvent, $decoded['event']);
        $this->assertSame('1', $decoded['schemaVersion']);
        $this->assertIsArray($decoded['properties']);
        $this->assertIsArray($decoded['tags']);
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    public static function canonicalEventFixtureProvider(): array
    {
        return [
            ['event-forms-submission.json', 'forms.submission.received'],
            ['event-system-stack-snapshot.json', 'system.stack.snapshot'],
            ['event-system-heartbeat-ping.json', 'system.heartbeat.ping'],
            ['event-ecommerce-order-placed.json', 'ecommerce.order.placed'],
            ['event-ecommerce-item-purchased.json', 'ecommerce.item.purchased'],
        ];
    }

    private static function specContractsDir(): string
    {
        $standalone = dirname(__DIR__) . '/spec/contracts';
        if (is_dir($standalone)) {
            return $standalone;
        }

        return dirname(__DIR__, 2) . '/spec/contracts';
    }
}
