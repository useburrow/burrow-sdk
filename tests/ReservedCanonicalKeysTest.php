<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Contracts\ReservedCanonicalKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReservedCanonicalKeysTest extends TestCase
{
    public function testFeedPrefixConstant(): void
    {
        $this->assertSame('feed_', ReservedCanonicalKeys::FEED_PREFIX);
    }

    public function testReservedKeyListMatchesBurrowSourceOfTruth(): void
    {
        $this->assertSame([
            'organizationId',
            'clientId',
            'projectId',
            'integrationId',
            'projectSourceId',
            'clientSourceId',
            'channel',
            'event',
            'timestamp',
            'source',
            'schemaVersion',
            'isLifecycle',
            'entityType',
            'externalEntityId',
            'externalEventId',
            'state',
            'stateChangedAt',
        ], ReservedCanonicalKeys::ALL);
    }

    #[DataProvider('reservedKeyProvider')]
    public function testDetectsReservedKeys(string $key): void
    {
        $this->assertTrue(ReservedCanonicalKeys::isReserved($key));
    }

    /**
     * @return list<array{0:string}>
     */
    public static function reservedKeyProvider(): array
    {
        return array_map(
            static fn (string $key): array => [$key],
            ReservedCanonicalKeys::ALL
        );
    }

    #[DataProvider('nonReservedKeyProvider')]
    public function testDoesNotTreatCommonFormKeysAsReserved(string $key): void
    {
        $this->assertFalse(ReservedCanonicalKeys::isReserved($key));
    }

    /**
     * @return list<array{0:string}>
     */
    public static function nonReservedKeyProvider(): array
    {
        return [
            ['formId'],
            ['submissionId'],
            ['submittedAt'],
            ['serviceInterest'],
            ['feed_customField'],
            ['feed_channel'],
            ['Channel'],
        ];
    }

    public function testEmptyKeyReturnsEmptyStringWithoutRewrite(): void
    {
        $this->assertSame('', ReservedCanonicalKeys::sanitizeIncomingDimensionKey('   '));
        $this->assertSame('', ReservedCanonicalKeys::sanitizeCanonicalKey(''));
    }

    public function testPrefixesReservedKeysWithFeedNamespace(): void
    {
        $this->assertSame('feed_channel', ReservedCanonicalKeys::sanitizeIncomingDimensionKey('channel'));
        $this->assertSame('feed_source', ReservedCanonicalKeys::sanitizeIncomingDimensionKey('source'));
    }

    public function testDoesNotDoublePrefixFeedKeys(): void
    {
        $this->assertSame('feed_source', ReservedCanonicalKeys::sanitizeIncomingDimensionKey('feed_source'));
        $this->assertSame('feed_customField', ReservedCanonicalKeys::sanitizeIncomingDimensionKey('feed_customField'));
    }

    public function testSanitizesRuntimePropertyAndTagMaps(): void
    {
        $result = ReservedCanonicalKeys::sanitizePropertyAndTagKeys([
            'serviceInterest' => 'Web Design',
            'channel' => 'email',
            'feed_customField' => 'kept',
            'submissionId' => 'sub_123',
            '   ' => 'ignored',
        ]);

        $this->assertSame([
            'serviceInterest' => 'Web Design',
            'feed_channel' => 'email',
            'feed_customField' => 'kept',
            'submissionId' => 'sub_123',
        ], $result);
    }

    public function testFixtureParityCases(): void
    {
        $fixturePath = self::specFixturesDir() . '/reserved-canonical-keys.json';
        $contents = file_get_contents($fixturePath);
        self::assertNotFalse($contents);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('cases', $decoded);

        foreach ($decoded['cases'] as $case) {
            self::assertIsArray($case);
            $this->assertSame(
                $case['output'],
                ReservedCanonicalKeys::sanitizeIncomingDimensionKey((string) $case['input']),
                (string) $case['input']
            );
        }
    }

    private static function specFixturesDir(): string
    {
        $standalone = dirname(__DIR__) . '/spec/fixtures';
        if (is_dir($standalone)) {
            return $standalone;
        }

        return dirname(__DIR__, 2) . '/spec/fixtures';
    }
}
