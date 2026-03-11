<?php

declare(strict_types=1);

namespace Burrow\Sdk\Outbox;

use Burrow\Sdk\Client\BurrowClientInterface;

final class OutboxDelivery
{
    private OutboxWorker $worker;

    public function __construct(
        private readonly OutboxStoreInterface $store,
        BurrowClientInterface $client,
        int $maxAttempts = 5,
        ?BackoffStrategyInterface $backoffStrategy = null
    ) {
        $this->worker = new OutboxWorker(
            store: $store,
            client: $client,
            maxAttempts: $maxAttempts,
            backoffStrategy: $backoffStrategy ?? new ExponentialBackoffStrategy()
        );
    }

    /**
     * @param list<array<string,mixed>> $events
     * @param array<string,mixed> $context
     * @return array{
     *   enqueued:int,
     *   deduped:int,
     *   items:list<array{eventKey:string,deduped:bool}>
     * }
     */
    public function enqueueEvents(array $events, array $context = []): array
    {
        $enqueued = 0;
        $deduped = 0;
        $items = [];

        foreach ($events as $event) {
            $key = EventKeyGenerator::buildDeterministic($event, $context);
            $eventKey = $key['eventKey'];
            if ($this->store->isEventSent($eventKey)) {
                $deduped++;
                $items[] = ['eventKey' => $eventKey, 'deduped' => true];
                continue;
            }

            $result = $this->store->enqueue($eventKey, $event);
            if ($result->deduped) {
                $deduped++;
            } else {
                $enqueued++;
            }
            $items[] = ['eventKey' => $eventKey, 'deduped' => $result->deduped];
        }

        return [
            'enqueued' => $enqueued,
            'deduped' => $deduped,
            'items' => $items,
        ];
    }

    /**
     * @return array{processedCount:int,sentCount:int,retryingCount:int,failedCount:int,retried:int}
     */
    public function flushOutbox(int $limit = 50): array
    {
        $result = $this->worker->runOnce($limit);
        return [
            'processedCount' => $result->processedCount,
            'sentCount' => $result->sentCount,
            'retryingCount' => $result->retryingCount,
            'failedCount' => $result->failedCount,
            'retried' => $result->retryingCount,
        ];
    }

    public function getOutboxStats(): OutboxStats
    {
        return $this->store->getStats();
    }

    /**
     * @param list<array<string,mixed>> $events
     * @param array<string,mixed> $context
     * @return array{
     *   enqueued:int,
     *   deduped:int,
     *   sent:int,
     *   retried:int,
     *   failed:int,
     *   checkpointAdvanceSafe:bool
     * }
     */
    public function runBackfillBatch(array $events, array $context = [], int $flushLimit = 50): array
    {
        $enqueue = $this->enqueueEvents($events, $context);
        $flush = $this->flushOutbox($flushLimit);
        $stats = $this->getOutboxStats();

        return [
            'enqueued' => $enqueue['enqueued'],
            'deduped' => $enqueue['deduped'],
            'sent' => $flush['sentCount'],
            'retried' => $flush['retryingCount'],
            'failed' => $flush['failedCount'],
            'checkpointAdvanceSafe' => $stats->pending === 0 && $stats->retrying === 0,
        ];
    }
}
