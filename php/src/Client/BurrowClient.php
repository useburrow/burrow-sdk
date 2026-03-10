<?php

declare(strict_types=1);

namespace Burrow\Sdk\Client;

use Burrow\Sdk\Client\Exception\UnexpectedResponseStatusException;
use Burrow\Sdk\Contracts\BackfillEventsRequest;
use Burrow\Sdk\Contracts\FormsContractSubmissionRequest;
use Burrow\Sdk\Contracts\OnboardingDiscoveryRequest;
use Burrow\Sdk\Contracts\OnboardingLinkRequest;
use Burrow\Sdk\Transport\ApiKeyAuthHeaderProvider;
use Burrow\Sdk\Transport\HttpResponse;
use Burrow\Sdk\Transport\HttpTransportInterface;

final class BurrowClient implements BurrowClientInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly HttpTransportInterface $transport
    ) {
    }

    public function discover(OnboardingDiscoveryRequest $request): HttpResponse
    {
        return $this->post('/api/v1/plugin-onboarding/discover', $request->toArray());
    }

    public function link(OnboardingLinkRequest $request): HttpResponse
    {
        return $this->post('/api/v1/plugin-onboarding/link', $request->toArray());
    }

    public function submitFormsContract(FormsContractSubmissionRequest $request): HttpResponse
    {
        return $this->post('/api/v1/plugin-onboarding/forms/contracts', $request->toArray());
    }

    public function publishEvent(array $event): HttpResponse
    {
        return $this->post('/api/v1/events', $event);
    }

    public function backfillEvents(
        BackfillEventsRequest $request,
        ?BackfillOptions $options = null,
        ?callable $progressCallback = null
    ): BackfillEventsResult {
        $options ??= new BackfillOptions();
        $batchSize = min(100, max(1, $options->batchSize));
        $concurrency = max(1, $options->concurrency);

        /** @var list<array<string,mixed>> $accepted */
        $accepted = [];
        /** @var list<array<string,mixed>> $rejected */
        $rejected = [];

        $chunks = array_chunk($request->events, $batchSize);
        $queuedCount = count($chunks);
        $completedCount = 0;
        $failedCount = 0;
        $latestCursor = $request->backfill->cursor;

        $this->emitProgress(
            $progressCallback,
            'queued',
            $queuedCount,
            0,
            $completedCount,
            $failedCount,
            0,
            0,
            $latestCursor
        );

        foreach (array_chunk($chunks, $concurrency) as $chunkWindow) {
            $this->emitProgress(
                $progressCallback,
                'running',
                $queuedCount - $completedCount - $failedCount,
                count($chunkWindow),
                $completedCount,
                $failedCount,
                count($accepted),
                count($rejected),
                $latestCursor
            );

            foreach ($chunkWindow as $eventChunk) {
                try {
                    $response = $this->submitBackfillChunkWithRetry(
                        events: $eventChunk,
                        request: $request,
                        options: $options
                    );
                } catch (\Throwable $exception) {
                    $failedCount++;
                    $this->emitProgress(
                        $progressCallback,
                        'failed',
                        $queuedCount - $completedCount - $failedCount,
                        0,
                        $completedCount,
                        $failedCount,
                        count($accepted),
                        count($rejected),
                        $latestCursor
                    );
                    throw $exception;
                }

                $body = $response->body ?? [];
                $acceptedChunk = is_array($body['accepted'] ?? null) ? $body['accepted'] : [];
                $rejectedChunk = is_array($body['rejected'] ?? null) ? $body['rejected'] : [];

                /** @var list<array<string,mixed>> $acceptedChunk */
                $acceptedChunk = array_values(array_filter($acceptedChunk, static fn ($row): bool => is_array($row)));
                /** @var list<array<string,mixed>> $rejectedChunk */
                $rejectedChunk = array_values(array_filter($rejectedChunk, static fn ($row): bool => is_array($row)));

                $accepted = [...$accepted, ...$acceptedChunk];
                $rejected = [...$rejected, ...$rejectedChunk];

                $backfillMeta = $body['backfill'] ?? null;
                if (is_array($backfillMeta) && isset($backfillMeta['cursor']) && is_string($backfillMeta['cursor'])) {
                    $latestCursor = $backfillMeta['cursor'];
                }

                $completedCount++;
            }
        }

        $requestedCount = count($request->events);
        $acceptedCount = count($accepted);
        $rejectedCount = count($rejected);

        $this->emitProgress(
            $progressCallback,
            'completed',
            0,
            0,
            $completedCount,
            $failedCount,
            $acceptedCount,
            $rejectedCount,
            $latestCursor
        );

        return new BackfillEventsResult(
            accepted: $accepted,
            rejected: $rejected,
            requestedCount: $requestedCount,
            acceptedCount: $acceptedCount,
            rejectedCount: $rejectedCount,
            latestCursor: $latestCursor
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function post(string $path, array $payload, ?array $acceptedStatuses = null): HttpResponse
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $headers = ApiKeyAuthHeaderProvider::fromApiKey($this->apiKey);
        $response = $this->transport->post($url, $headers, $payload);

        $acceptedStatuses ??= [200, 207];
        if (!in_array($response->status, $acceptedStatuses, true)) {
            throw new UnexpectedResponseStatusException($path, $response);
        }

        return $response;
    }

    /**
     * @param list<array<string,mixed>> $events
     */
    private function submitBackfillChunkWithRetry(
        array $events,
        BackfillEventsRequest $request,
        BackfillOptions $options
    ): HttpResponse {
        $attempt = 0;
        do {
            $attempt++;

            try {
                return $this->post('/api/v1/plugin-backfill/events', [
                    'events' => $events,
                    'backfill' => $request->backfill->toArray(),
                ], [200, 207]);
            } catch (UnexpectedResponseStatusException $exception) {
                if (!$this->isRetryableBackfillStatus($exception) || $attempt >= $options->maxAttempts) {
                    throw $exception;
                }

                $this->sleepForBackfillRetry($attempt, $options, $exception->response);
            } catch (\RuntimeException $exception) {
                if ($attempt >= $options->maxAttempts) {
                    throw $exception;
                }
                $this->sleepForBackfillRetry($attempt, $options, null);
            }
        } while ($attempt < $options->maxAttempts);

        throw new \RuntimeException('Backfill chunk retries exhausted.');
    }

    private function isRetryableBackfillStatus(UnexpectedResponseStatusException $exception): bool
    {
        $status = $exception->response->status;
        return $status === 429 || ($status >= 500 && $status <= 599);
    }

    private function sleepForBackfillRetry(int $attempt, BackfillOptions $options, ?HttpResponse $response): void
    {
        $delayMilliseconds = $this->computeRetryDelayMilliseconds($attempt, $options, $response);
        if ($delayMilliseconds <= 0) {
            return;
        }

        usleep($delayMilliseconds * 1_000);
    }

    private function computeRetryDelayMilliseconds(
        int $attempt,
        BackfillOptions $options,
        ?HttpResponse $response
    ): int {
        if ($response !== null && $response->status === 429) {
            $retryAfter = $response->headers['retry-after'] ?? null;
            if (is_string($retryAfter) && $retryAfter !== '') {
                if (is_numeric($retryAfter)) {
                    return max(0, (int) $retryAfter * 1_000);
                }

                $timestamp = strtotime($retryAfter);
                if ($timestamp !== false) {
                    $seconds = max(0, $timestamp - time());
                    return $seconds * 1_000;
                }
            }
        }

        $delay = (int) round($options->baseDelayMilliseconds * (2 ** ($attempt - 1)));
        return min($delay, $options->maxDelayMilliseconds);
    }

    /**
     * @param callable(BackfillProgressUpdate):void|null $progressCallback
     */
    private function emitProgress(
        ?callable $progressCallback,
        string $status,
        int $queuedCount,
        int $runningCount,
        int $completedCount,
        int $failedCount,
        int $acceptedCount,
        int $rejectedCount,
        ?string $latestCursor
    ): void {
        if ($progressCallback === null) {
            return;
        }

        $progressCallback(new BackfillProgressUpdate(
            status: $status,
            queuedCount: $queuedCount,
            runningCount: $runningCount,
            completedCount: $completedCount,
            failedCount: $failedCount,
            acceptedCount: $acceptedCount,
            rejectedCount: $rejectedCount,
            latestCursor: $latestCursor
        ));
    }
}
