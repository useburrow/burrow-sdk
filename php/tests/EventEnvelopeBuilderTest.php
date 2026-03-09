<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Events\EventEnvelopeBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EventEnvelopeBuilderTest extends TestCase
{
    public function testBuildsEnvelopeWithDefaults(): void
    {
        $event = EventEnvelopeBuilder::build([
            'organizationId' => 'org_123',
            'clientId' => 'client_123',
            'channel' => 'forms',
            'event' => 'forms.submission.received',
            'timestamp' => '2026-03-07T00:00:00.000Z',
        ]);

        $this->assertSame('1', $event['schemaVersion']);
        $this->assertSame([], $event['properties']);
        $this->assertSame([], $event['tags']);
    }

    public function testThrowsForMissingRequiredField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EventEnvelopeBuilder::build([
            'organizationId' => 'org_123',
            'clientId' => 'client_123',
            'channel' => 'forms',
            'event' => 'forms.submission.received',
        ]);
    }
}
