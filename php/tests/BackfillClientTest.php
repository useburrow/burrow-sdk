<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Closure;
use Burrow\Sdk\Client\BackfillOptions;
use Burrow\Sdk\Client\BackfillProgressUpdate;
use Burrow\Sdk\Client\BurrowClient;
use Burrow\Sdk\Client\Exception\SdkApiException;
use Burrow\Sdk\Client\Exception\SdkPreflightException;
use Burrow\Sdk\Contracts\BackfillEventsRequest;
use Burrow\Sdk\Contracts\BackfillWindow;
use Burrow\Sdk\Transport\ConcurrentHttpTransportInterface;
use Burrow\Sdk\Transport\HttpResponse;
use Burrow\Sdk\Transport\HttpTransportInterface;
use PHPUnit\Framework\TestCase;

final class BackfillClientTest extends TestCase
{
    public function testRequestSerializationIncludesEventsAndBackfillWindow(): void
    {
        $request = new BackfillEventsRequest(
            events: [
                ['event' => 'forms.submission.received', 'clientId' => 'cli_123', 'timestamp' => '2026-03-01T12:00:00.000Z'],
            ],
            backfill: new BackfillWindow(
                windowStart: '2026-03-01T00:00:00.000Z',
                cursor: 'cursor_123',
                windowEnd: '2026-03-02T00:00:00.000Z',
                source: 'wordpress-plugin'
            )
        );

        $payload = $request->toArray();
        $this->assertArrayHasKey('events', $payload);
        $this->assertArrayHasKey('backfill', $payload);
        $this->assertSame('cursor_123', $payload['backfill']['cursor']);
        $this->assertSame('2026-03-01T00:00:00.000Z', $payload['backfill']['windowStart']);
    }

    public function testChunksBackfillAtOneHundredAndUsesDefaultConcurrencyFour(): void
    {
        $progress = [];
        $transport = new InspectingBackfillTransport();
        $client = new BurrowClient('https://api.example.com', 'secret_key', $transport);

        $events = [];
        for ($index = 1; $index <= 205; $index++) {
            $events[] = $this->makeBackfillEvent('sub_' . $index, '2026-03-01T12:00:00.000Z');
        }

        $client->backfillEvents(
            new BackfillEventsRequest(
                events: $events,
                backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z')
            ),
            null,
            static function (BackfillProgressUpdate $update) use (&$progress): void {
                $progress[] = $update;
            }
        );

        $this->assertCount(3, $transport->payloads);
        $this->assertCount(100, $transport->payloads[0]['events']);
        $this->assertCount(100, $transport->payloads[1]['events']);
        $this->assertCount(5, $transport->payloads[2]['events']);

        $running = array_values(array_filter($progress, static fn (BackfillProgressUpdate $update): bool => $update->status === 'running'));
        $this->assertNotEmpty($running);
        $this->assertSame(3, $running[0]->runningCount);
    }

    public function testRetriesOn429WithRetryAfterHeader(): void
    {
        $transport = new Retry429BackfillTransport();
        $client = new BurrowClient('https://api.example.com', 'secret_key', $transport);

        $result = $client->backfillEvents(
            new BackfillEventsRequest(
                events: [$this->makeBackfillEvent('sub_123', '2026-03-01T12:00:00.000Z')],
                backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z')
            ),
            new BackfillOptions(maxAttempts: 3, baseDelayMilliseconds: 1, maxDelayMilliseconds: 1)
        );

        $this->assertSame(2, $transport->callCount);
        $this->assertSame(1, $result->acceptedCount);
        $this->assertSame(0, $result->rejectedCount);
        $this->assertSame(0, $result->validationRejectedCount);
    }

    public function testUsesConcurrentTransportWhenAvailable(): void
    {
        $transport = new ConcurrentInspectingBackfillTransport();
        $client = new BurrowClient('https://api.example.com', 'secret_key', $transport);

        $events = [];
        for ($index = 1; $index <= 105; $index++) {
            $events[] = $this->makeBackfillEvent('sub_' . $index, '2026-03-01T12:00:00.000Z') + ['externalEventId' => 'evt_' . $index];
        }

        $result = $client->backfillEvents(
            new BackfillEventsRequest(
                events: $events,
                backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z')
            ),
            new BackfillOptions(batchSize: 100, concurrency: 4, maxAttempts: 2)
        );

        $this->assertSame(1, $transport->concurrentCallCount);
        $this->assertSame(2, $transport->windowSizes[0]);
        $this->assertSame(105, $result->acceptedCount);
    }

    public function testReturnsPartialAcceptedAndRejectedItems(): void
    {
        $transport = new InspectingBackfillTransport(
            static fn (): HttpResponse => new HttpResponse(
                status: 207,
                body: [
                    'accepted' => [['externalEventId' => 'evt_1']],
                    'rejected' => [['externalEventId' => 'evt_2', 'reason' => 'invalid payload']],
                    'summary' => [
                        'requestedCount' => 2,
                        'acceptedCount' => 1,
                        'rejectedCount' => 1,
                    ],
                    'backfill' => ['cursor' => 'cursor_final'],
                ],
                raw: '{"partial":true}'
            )
        );
        $client = new BurrowClient('https://api.example.com', 'secret_key', $transport);

        $result = $client->backfillEvents(new BackfillEventsRequest(
            events: [
                $this->makeBackfillEvent('sub_1', '2026-03-01T12:00:00.000Z') + ['externalEventId' => 'evt_1'],
                $this->makeBackfillEvent('sub_2', '2026-03-01T12:00:00.000Z') + ['externalEventId' => 'evt_2'],
            ],
            backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z')
        ));

        $this->assertSame(2, $result->requestedCount);
        $this->assertSame(1, $result->acceptedCount);
        $this->assertSame(1, $result->rejectedCount);
        $this->assertSame(0, $result->validationRejectedCount);
        $this->assertSame('cursor_final', $result->latestCursor);
        $this->assertSame('evt_2', $result->rejected[0]['externalEventId']);
    }

    public function testRejectsMissingTimestampAndContinuesWithValidRecords(): void
    {
        $transport = new InspectingBackfillTransport();
        $client = new BurrowClient('https://api.example.com', 'secret_key', $transport);

        $result = $client->backfillEvents(new BackfillEventsRequest(
            events: [
                $this->makeBackfillEvent('sub_1', '2026-03-01T12:00:00.000Z'),
                ['event' => 'forms.submission.received', 'submissionId' => 'sub_missing'],
            ],
            backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z')
        ));

        $this->assertCount(1, $transport->payloads);
        $this->assertCount(1, $transport->payloads[0]['events']);
        $this->assertSame(1, $result->validationRejectedCount);
        $this->assertSame('missing_timestamp', $result->validationRejections[0]['reason']);
        $this->assertSame(1, $result->rejectedCount);
    }

    public function testRejectsInvalidTimestampAndDoesNotFallbackToNow(): void
    {
        $transport = new InspectingBackfillTransport();
        $client = new BurrowClient('https://api.example.com', 'secret_key', $transport);

        $result = $client->backfillEvents(new BackfillEventsRequest(
            events: [
                $this->makeBackfillEvent('sub_valid', '2026-03-01T12:00:00.000Z'),
                $this->makeBackfillEvent('sub_invalid', 'not-a-date'),
            ],
            backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z')
        ));

        $this->assertCount(1, $transport->payloads);
        $sentEvent = $transport->payloads[0]['events'][0];
        $this->assertSame('2026-03-01T12:00:00.000Z', $sentEvent['timestamp']);
        $this->assertSame(1, $result->validationRejectedCount);
        $this->assertSame('invalid_timestamp', $result->validationRejections[0]['reason']);
        $this->assertSame(1, $result->rejectedCount);
    }

    public function testFormsBackfillPreflightFailsWhenIngestionKeyMissing(): void
    {
        $transport = new InspectingBackfillTransport();
        $state = \Burrow\Sdk\Client\BurrowClientState::fromArray([
            'projectId' => 'prj_123',
            'formsProjectSourceId' => 'src_forms_123',
        ]);
        $client = new BurrowClient('https://api.example.com', 'bootstrap', $transport, $state);

        $this->expectException(SdkPreflightException::class);
        $this->expectExceptionMessage('Cannot run forms backfill without an ingestion key.');

        $client->backfillEvents(new BackfillEventsRequest(
            events: [$this->makeBackfillEvent('sub_1', '2026-03-01T12:00:00.000Z')],
            backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z'),
            channel: 'forms'
        ));
    }

    public function testFormsBackfillPreflightFailsWhenProjectIdMissing(): void
    {
        $transport = new InspectingBackfillTransport();
        $state = \Burrow\Sdk\Client\BurrowClientState::fromArray([
            'ingestionKey' => 'ing_key',
            'formsProjectSourceId' => 'src_forms_123',
        ]);
        $client = new BurrowClient('https://api.example.com', 'bootstrap', $transport, $state);

        $this->expectException(SdkPreflightException::class);
        $this->expectExceptionMessage('Cannot run forms backfill without a projectId.');

        $client->backfillEvents(new BackfillEventsRequest(
            events: [$this->makeBackfillEvent('sub_1', '2026-03-01T12:00:00.000Z')],
            backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z'),
            channel: 'forms'
        ));
    }

    public function testFormsBackfillPreflightFailsWhenProjectSourceIdMissing(): void
    {
        $transport = new InspectingBackfillTransport();
        $state = \Burrow\Sdk\Client\BurrowClientState::fromArray([
            'ingestionKey' => 'ing_key',
            'projectId' => 'prj_123',
        ]);
        $client = new BurrowClient('https://api.example.com', 'bootstrap', $transport, $state);

        $this->expectException(SdkPreflightException::class);
        $this->expectExceptionMessage('Cannot run forms backfill without a projectSourceId.');

        $client->backfillEvents(new BackfillEventsRequest(
            events: [$this->makeBackfillEvent('sub_1', '2026-03-01T12:00:00.000Z')],
            backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z'),
            channel: 'forms'
        ));
    }

    public function testMaps401InvalidIngestionKeyError(): void
    {
        $transport = new InspectingBackfillTransport(
            static fn (): HttpResponse => new HttpResponse(
                status: 401,
                body: ['error' => ['message' => 'Invalid key']],
                raw: '{"error":{"message":"Invalid key"}}'
            )
        );
        $state = \Burrow\Sdk\Client\BurrowClientState::fromArray([
            'ingestionKey' => 'ing_key',
            'projectId' => 'prj_123',
            'formsProjectSourceId' => 'src_forms_123',
        ]);
        $client = new BurrowClient('https://api.example.com', 'bootstrap', $transport, $state);

        try {
            $client->backfillEvents(new BackfillEventsRequest(
                events: [$this->makeBackfillEvent('sub_1', '2026-03-01T12:00:00.000Z')],
                backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z'),
                channel: 'forms'
            ));
            self::fail('Expected SdkApiException to be thrown.');
        } catch (SdkApiException $exception) {
            $this->assertSame('INVALID_INGESTION_API_KEY', $exception->codeName);
            $this->assertFalse($exception->retryable);
        }
    }

    public function testMaps400FormsBackfillAttributionRequiredError(): void
    {
        $transport = new InspectingBackfillTransport(
            static fn (): HttpResponse => new HttpResponse(
                status: 400,
                body: ['error' => ['code' => 'FORMS_BACKFILL_ATTRIBUTION_REQUIRED', 'message' => 'routing missing']],
                raw: '{"error":{"code":"FORMS_BACKFILL_ATTRIBUTION_REQUIRED","message":"routing missing"}}'
            )
        );
        $state = \Burrow\Sdk\Client\BurrowClientState::fromArray([
            'ingestionKey' => 'ing_key',
            'projectId' => 'prj_123',
            'formsProjectSourceId' => 'src_forms_123',
        ]);
        $client = new BurrowClient('https://api.example.com', 'bootstrap', $transport, $state);

        try {
            $client->backfillEvents(new BackfillEventsRequest(
                events: [$this->makeBackfillEvent('sub_1', '2026-03-01T12:00:00.000Z')],
                backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z'),
                channel: 'forms'
            ));
            self::fail('Expected SdkApiException to be thrown.');
        } catch (SdkApiException $exception) {
            $this->assertSame('FORMS_BACKFILL_ATTRIBUTION_REQUIRED', $exception->codeName);
            $this->assertFalse($exception->retryable);
        }
    }

    public function testLinkContractsBackfillAutoUsesRoutingState(): void
    {
        $transport = new SequencedBackfillTransport([
            new HttpResponse(200, [
                'ingestionKey' => ['key' => 'ingestion_prj_key', 'scope' => 'project', 'projectId' => 'prj_123'],
                'project' => ['id' => 'prj_123', 'clientId' => 'cli_123'],
            ], '{"ok":true}'),
            new HttpResponse(200, [
                'projectSourceId' => 'src_forms_123',
                'contractsVersion' => 'v1',
                'contractMappings' => [['contractId' => 'ct_123', 'enabled' => true]],
            ], '{"ok":true}'),
            new HttpResponse(200, [
                'accepted' => [['externalEventId' => 'evt_1']],
                'rejected' => [],
            ], '{"ok":true}'),
        ]);
        $client = new BurrowClient('https://api.example.com', 'bootstrap_key', $transport);

        $client->link(new \Burrow\Sdk\Contracts\OnboardingLinkRequest(
            site: ['url' => 'https://site.test'],
            selection: ['organizationId' => 'org_123', 'projectId' => 'prj_123']
        ));
        $client->submitFormsContract(new \Burrow\Sdk\Contracts\FormsContractSubmissionRequest([
            'platform' => 'wordpress',
            'routing' => ['projectId' => 'prj_123'],
            'formsContracts' => [],
        ]));
        $client->backfillEvents(new BackfillEventsRequest(
            events: [[
                'timestamp' => '2026-03-01T12:00:00.000Z',
                'source' => 'gravity-forms',
            ]],
            backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z'),
            channel: 'forms'
        ));

        $payload = $transport->payloads[2];
        $this->assertSame([
            'projectId' => 'prj_123',
            'projectSourceId' => 'src_forms_123',
            'clientId' => 'cli_123',
        ], $payload['routing']);
        $this->assertSame('forms', $payload['events'][0]['channel']);
        $this->assertSame('forms.submission.received', $payload['events'][0]['event']);
        $this->assertSame('gravity-forms', $payload['events'][0]['source']);
    }

    /**
     * @return array<string,mixed>
     */
    private function makeBackfillEvent(string $submissionId, string $timestamp): array
    {
        return [
            'organizationId' => 'org_123',
            'clientId' => 'cli_123',
            'channel' => 'forms',
            'event' => 'forms.submission.received',
            'timestamp' => $timestamp,
            'submissionId' => $submissionId,
        ];
    }
}

final class InspectingBackfillTransport implements HttpTransportInterface
{
    /** @var list<array<string,mixed>> */
    public array $payloads = [];

    /**
     * @param Closure():HttpResponse|null $responseFactory
     */
    public function __construct(private readonly ?Closure $responseFactory = null)
    {
    }

    public function post(string $url, array $headers, array $payload): HttpResponse
    {
        $this->payloads[] = $payload;

        if ($this->responseFactory !== null) {
            return ($this->responseFactory)();
        }

        return new HttpResponse(
            status: 200,
            body: [
                'accepted' => $payload['events'] ?? [],
                'rejected' => [],
                'summary' => [
                    'requestedCount' => is_array($payload['events'] ?? null) ? count($payload['events']) : 0,
                    'acceptedCount' => is_array($payload['events'] ?? null) ? count($payload['events']) : 0,
                    'rejectedCount' => 0,
                ],
                'backfill' => ['cursor' => 'cursor_' . count($this->payloads)],
            ],
            raw: '{"ok":true}'
        );
    }
}

final class Retry429BackfillTransport implements HttpTransportInterface
{
    public int $callCount = 0;

    public function post(string $url, array $headers, array $payload): HttpResponse
    {
        $this->callCount++;
        if ($this->callCount === 1) {
            return new HttpResponse(
                status: 429,
                body: ['error' => 'rate limited'],
                raw: '{"error":"rate limited"}',
                headers: ['retry-after' => '0']
            );
        }

        return new HttpResponse(
            status: 200,
            body: [
                'accepted' => [['externalEventId' => 'evt_1']],
                'rejected' => [],
                'summary' => [
                    'requestedCount' => 1,
                    'acceptedCount' => 1,
                    'rejectedCount' => 0,
                ],
            ],
            raw: '{"ok":true}'
        );
    }
}

final class ConcurrentInspectingBackfillTransport implements ConcurrentHttpTransportInterface
{
    public int $concurrentCallCount = 0;

    /** @var list<int> */
    public array $windowSizes = [];

    public function post(string $url, array $headers, array $payload): HttpResponse
    {
        return new HttpResponse(
            status: 200,
            body: [
                'accepted' => $payload['events'] ?? [],
                'rejected' => [],
            ],
            raw: '{"ok":true}'
        );
    }

    public function postConcurrent(array $requests): array
    {
        $this->concurrentCallCount++;
        $this->windowSizes[] = count($requests);

        $responses = [];
        foreach ($requests as $request) {
            $responses[] = new HttpResponse(
                status: 200,
                body: [
                    'accepted' => $request['payload']['events'] ?? [],
                    'rejected' => [],
                ],
                raw: '{"ok":true}'
            );
        }

        return $responses;
    }
}

final class SequencedBackfillTransport implements HttpTransportInterface
{
    /** @var list<HttpResponse> */
    private array $responses;

    /** @var list<array<string,mixed>> */
    public array $payloads = [];

    /**
     * @param list<HttpResponse> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function post(string $url, array $headers, array $payload): HttpResponse
    {
        $this->payloads[] = $payload;
        $response = array_shift($this->responses);
        if ($response === null) {
            throw new \RuntimeException('No response left in queue.');
        }

        return $response;
    }
}
