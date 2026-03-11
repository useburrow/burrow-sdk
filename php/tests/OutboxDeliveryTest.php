<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Client\BackfillEventsResult;
use Burrow\Sdk\Client\BackfillOptions;
use Burrow\Sdk\Client\BurrowClientInterface;
use Burrow\Sdk\Contracts\BackfillEventsRequest;
use Burrow\Sdk\Contracts\FormsContractSubmissionRequest;
use Burrow\Sdk\Contracts\FormsContractsResponse;
use Burrow\Sdk\Contracts\LinkedProjectDeepLink;
use Burrow\Sdk\Contracts\OnboardingDiscoveryRequest;
use Burrow\Sdk\Contracts\OnboardingLinkRequest;
use Burrow\Sdk\Contracts\OnboardingLinkResponse;
use Burrow\Sdk\Outbox\EventKeyGenerator;
use Burrow\Sdk\Outbox\InMemoryOutboxStore;
use Burrow\Sdk\Outbox\OutboxDelivery;
use Burrow\Sdk\Transport\Exception\TransportFailureException;
use Burrow\Sdk\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

final class OutboxDeliveryTest extends TestCase
{
    public function testSecondEnqueueIsDedupedAndNotSentTwice(): void
    {
        $store = new InMemoryOutboxStore();
        $delivery = new OutboxDelivery($store, new DeliverySequenceClient([
            new HttpResponse(200, ['ok' => true], '{"ok":true}'),
        ]));
        $event = $this->makeEvent('sub_1');

        $first = $delivery->enqueueEvents([$event]);
        $second = $delivery->enqueueEvents([$event]);
        $flush = $delivery->flushOutbox();

        $this->assertSame(1, $first['enqueued']);
        $this->assertSame(1, $second['deduped']);
        $this->assertSame(1, $flush['sentCount']);
    }

    public function testTransientFailureThenSuccessCreatesSingleSentLedgerEntry(): void
    {
        $store = new InMemoryOutboxStore();
        $first = new OutboxDelivery($store, new DeliverySequenceClient([
            new TransportFailureException('timeout'),
        ]), backoffStrategy: new \Burrow\Sdk\Outbox\ExponentialBackoffStrategy(baseDelaySeconds: 0, multiplier: 1, maxDelaySeconds: 0));
        $second = new OutboxDelivery($store, new DeliverySequenceClient([
            new HttpResponse(200, ['ok' => true], '{"ok":true}'),
        ]), backoffStrategy: new \Burrow\Sdk\Outbox\ExponentialBackoffStrategy(baseDelaySeconds: 0, multiplier: 1, maxDelaySeconds: 0));

        $first->enqueueEvents([$this->makeEvent('sub_2')]);
        $retry = $first->flushOutbox();
        $sent = $second->flushOutbox();
        $stats = $second->getOutboxStats();

        $this->assertSame(1, $retry['retryingCount']);
        $this->assertSame(1, $sent['sentCount']);
        $this->assertSame(1, $stats->sentLedgerCount);
    }

    public function testDeterministicKeyStableAcrossRestarts(): void
    {
        $event = $this->makeEvent('stable_1');
        $first = EventKeyGenerator::buildDeterministic($event);
        $second = EventKeyGenerator::buildDeterministic($event);

        $this->assertSame($first['eventKey'], $second['eventKey']);
    }

    /**
     * @return array<string,mixed>
     */
    private function makeEvent(string $submissionId): array
    {
        return [
            'channel' => 'forms',
            'event' => 'forms.submission.received',
            'source' => 'gravity-forms',
            'projectId' => 'prj_123',
            'projectSourceId' => 'src_123',
            'submissionId' => $submissionId,
            'timestamp' => '2026-03-01T12:00:00.000Z',
        ];
    }
}

final class DeliverySequenceClient implements BurrowClientInterface
{
    /** @var list<HttpResponse|\Throwable> */
    private array $sequence;

    /**
     * @param list<HttpResponse|\Throwable> $sequence
     */
    public function __construct(array $sequence)
    {
        $this->sequence = $sequence;
    }

    public function discover(OnboardingDiscoveryRequest $request): HttpResponse
    {
        return $this->next();
    }

    public function link(OnboardingLinkRequest $request): OnboardingLinkResponse
    {
        return OnboardingLinkResponse::fromResponseBody($this->next()->body);
    }

    public function submitFormsContract(FormsContractSubmissionRequest $request): FormsContractsResponse
    {
        return FormsContractsResponse::fromResponseBody($this->next()->body);
    }

    public function fetchFormsContracts(string $projectId, string $platform): FormsContractsResponse
    {
        return FormsContractsResponse::fromResponseBody($this->next()->body);
    }

    public function getLinkedProjectDeepLink(): ?LinkedProjectDeepLink
    {
        return null;
    }

    public function getState(): \Burrow\Sdk\Client\BurrowClientState
    {
        return new \Burrow\Sdk\Client\BurrowClientState();
    }

    public function getProjectId(): ?string
    {
        return null;
    }

    public function getProjectSourceId(?string $channel = 'forms'): ?string
    {
        return null;
    }

    public function getBackfillRouting(string $channel): array
    {
        return ['projectId' => '', 'projectSourceId' => ''];
    }

    public function publishEvent(array $event): HttpResponse
    {
        return $this->next();
    }

    public function backfillEvents(
        BackfillEventsRequest $request,
        ?BackfillOptions $options = null,
        ?callable $progressCallback = null
    ): BackfillEventsResult {
        return new BackfillEventsResult(
            accepted: [],
            rejected: [],
            requestedCount: 0,
            acceptedCount: 0,
            rejectedCount: 0,
            validationRejectedCount: 0,
            validationRejections: [],
            latestCursor: null
        );
    }

    private function next(): HttpResponse
    {
        $item = array_shift($this->sequence);
        if ($item === null) {
            throw new \RuntimeException('No response left in sequence.');
        }
        if ($item instanceof \Throwable) {
            throw $item;
        }
        return $item;
    }
}
