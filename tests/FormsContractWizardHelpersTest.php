<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Contracts\FormsContractSubmissionRequest;
use Burrow\Sdk\Contracts\FormsContractWizardHelpers;
use Burrow\Sdk\Events\CanonicalEnvelopeBuilders;
use Burrow\Sdk\Events\ChannelRoutingResolver;
use Burrow\Sdk\Events\ChannelRoutingState;
use PHPUnit\Framework\TestCase;

final class FormsContractWizardHelpersTest extends TestCase
{
    public function testSanitizeFieldMappingPrefixesReservedCanonicalKey(): void
    {
        $result = FormsContractWizardHelpers::sanitizeFieldMapping([
            'externalFieldId' => 'channel',
            'sourceLabel' => 'Preferred Channel',
            'canonicalKey' => 'channel',
            'target' => 'tags',
        ]);

        $this->assertSame('feed_channel', $result['mapping']['canonicalKey']);
        $this->assertCount(1, $result['warnings']);
        $this->assertSame('RESERVED_CANONICAL_KEY_PREFIXED', $result['warnings'][0]['code']);
    }

    public function testSanitizeFormsContractSubmissionPayloadFromFixtureUsesBurrowPostRules(): void
    {
        $fixturePath = self::specContractsDir() . '/forms-contract-reserved-canonical-keys.request.json';
        $contents = file_get_contents($fixturePath);
        self::assertNotFalse($contents);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded);

        $payload = FormsContractWizardHelpers::sanitizeFormsContractSubmissionPayloadForPost($decoded);
        $mappings = $payload['formsContracts'][0]['fieldMappings'];

        $this->assertSame('feed_channel', $mappings[0]['canonicalKey']);
        $this->assertSame('feed_source', $mappings[1]['canonicalKey']);
        $this->assertSame('feed_customField', $mappings[2]['canonicalKey']);
        $this->assertSame('submissionId', $mappings[3]['canonicalKey']);
    }

    public function testWizardPayloadWarningsIncludeReservedPrefixing(): void
    {
        $fixturePath = self::specContractsDir() . '/forms-contract-reserved-canonical-keys.request.json';
        $contents = file_get_contents($fixturePath);
        self::assertNotFalse($contents);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded);

        $result = FormsContractWizardHelpers::sanitizeFormsContractSubmissionPayload($decoded);
        $warnings = $result['warnings'];

        $this->assertSame('feed_channel', $result['payload']['formsContracts'][0]['fieldMappings'][0]['canonicalKey']);
        $this->assertCount(2, $warnings);
        $this->assertSame('RESERVED_CANONICAL_KEY_PREFIXED', $warnings[0]['code']);
        $this->assertSame('RESERVED_CANONICAL_KEY_PREFIXED', $warnings[1]['code']);
    }

    public function testFormsContractSubmissionRequestSanitizesOnPost(): void
    {
        $request = new FormsContractSubmissionRequest([
            'platform' => 'craft',
            'routing' => ['projectId' => 'prj_123'],
            'formsContracts' => [[
                'provider' => 'freeform',
                'externalFormId' => 'ff_42',
                'formHandle' => 'contactUs',
                'formName' => 'Contact Us',
                'enabled' => true,
                'fieldMappings' => [[
                    'externalFieldId' => 'channel',
                    'sourceLabel' => 'Preferred Channel',
                    'canonicalKey' => 'channel',
                    'target' => 'tags',
                ]],
            ]],
        ]);

        $payload = $request->toArray();
        $warnings = $request->warnings();

        $this->assertSame('feed_channel', $payload['formsContracts'][0]['fieldMappings'][0]['canonicalKey']);
        $this->assertCount(1, $warnings);
        $this->assertSame('RESERVED_CANONICAL_KEY_PREFIXED', $warnings[0]['code']);
    }

    public function testWizardEmptyKeyDefaultsToField(): void
    {
        $result = FormsContractWizardHelpers::sanitizeCanonicalKey('   ');

        $this->assertSame('field', $result['key']);
        $this->assertSame('EMPTY_CANONICAL_KEY', $result['warnings'][0]['code']);
    }

    public function testWizardLabelSlugificationIsSeparateFromBurrowSanitizer(): void
    {
        $result = FormsContractWizardHelpers::sanitizeCanonicalKey('Preferred Channel');

        $this->assertSame('preferredChannel', $result['key']);
        $this->assertSame('INVALID_CANONICAL_KEY_NORMALIZED', $result['warnings'][0]['code']);
    }

    public function testLabelToCanonicalKeyMatchesCraftStyle(): void
    {
        $this->assertSame('serviceInterest', FormsContractWizardHelpers::labelToCanonicalKey('What service are you interested in?'));
        $this->assertSame('field', FormsContractWizardHelpers::labelToCanonicalKey('   '));
    }

    public function testRuntimeFormsBuilderSanitizesCustomMaps(): void
    {
        $resolver = new ChannelRoutingResolver(new ChannelRoutingState(
            projectId: 'prj_123',
            projectSourceIds: ['forms' => 'src_forms_123'],
            clientId: 'client_123'
        ));

        $event = CanonicalEnvelopeBuilders::buildFormsSubmissionReceivedEvent([
            'organizationId' => 'org_123',
            'formName' => 'Contact Us',
            'submissionId' => 'sub_98765',
            'submittedAt' => '2026-03-06T14:10:00.000Z',
            'formId' => 'contactUs',
            'tags' => [
                'serviceInterest' => 'Web Design',
                'channel' => 'email',
            ],
            'properties' => [
                'source' => 'should-be-prefixed',
            ],
        ], $resolver);

        $this->assertSame('forms.submission.received', $event['event']);
        $this->assertSame('sub_98765', $event['properties']['submissionId']);
        $this->assertSame('should-be-prefixed', $event['properties']['feed_source']);
        $this->assertArrayNotHasKey('source', $event['properties']);
        $this->assertSame('Web Design', $event['tags']['serviceInterest']);
        $this->assertSame('email', $event['tags']['feed_channel']);
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
