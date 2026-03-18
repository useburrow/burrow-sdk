<?php

declare(strict_types=1);

namespace Burrow\Sdk\Client;

use DateTimeImmutable;
use DateTimeZone;
use Burrow\Sdk\Client\Exception\SdkApiException;
use Burrow\Sdk\Client\Exception\SdkErrorClassifier;
use Burrow\Sdk\Client\Exception\SdkPreflightException;
use Burrow\Sdk\Contracts\BackfillEventsRequest;
use Burrow\Sdk\Contracts\FormsContractsFetchRequest;
use Burrow\Sdk\Contracts\FormsContractSubmissionRequest;
use Burrow\Sdk\Contracts\FormsContractsResponse;
use Burrow\Sdk\Contracts\LinkedProjectDeepLink;
use Burrow\Sdk\Contracts\OnboardingDiscoveryRequest;
use Burrow\Sdk\Contracts\OnboardingLinkRequest;
use Burrow\Sdk\Contracts\OnboardingLinkResponse;
use Burrow\Sdk\Events\EventEnvelopeBuilder;
use Burrow\Sdk\Events\Exception\EventContractException;
use Burrow\Sdk\Transport\ApiKeyAuthHeaderProvider;
use Burrow\Sdk\Transport\ConcurrentHttpTransportInterface;
use Burrow\Sdk\Transport\HttpResponse;
use Burrow\Sdk\Transport\HttpTransportInterface;

final class BurrowClient implements BurrowClientInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private string $apiKey,
        private readonly HttpTransportInterface $transport,
        ?BurrowClientState $state = null,
        ?callable $debugLogger = null
    ) {
        $this->debugLogger = $debugLogger !== null ? \Closure::fromCallable($debugLogger) : null;
        $this->state = $state ?? new BurrowClientState();
        if ($this->state->ingestionKey !== null && $this->state->ingestionKey !== '') {
            $this->apiKey = $this->state->ingestionKey;
        }
        $this->scopedProjectId = $this->state->projectId;
    }

    private ?string $scopedProjectId = null;
    private ?OnboardingLinkResponse $lastLinkResponse = null;
    private BurrowClientState $state;
    private ?\Closure $debugLogger = null;

    public function discover(OnboardingDiscoveryRequest $request): HttpResponse
    {
        return $this->post('/api/v1/plugin-onboarding/discover', $request->toArray());
    }

    public function link(OnboardingLinkRequest $request): OnboardingLinkResponse
    {
        $response = $this->post('/api/v1/plugin-onboarding/link', $request->toArray());
        $parsed = OnboardingLinkResponse::fromResponseBody($response->body);
        $this->lastLinkResponse = $parsed;

        if ($parsed->ingestionKey !== null && $parsed->ingestionKey->key !== '') {
            $this->apiKey = $parsed->ingestionKey->key;
            $this->state->ingestionKey = $parsed->ingestionKey->key;
        }

        $linkedProjectId = $parsed->project?->id
            ?? $parsed->ingestionKey?->projectId
            ?? (isset($parsed->routing['projectId']) ? trim((string) $parsed->routing['projectId']) : null);
        if ($linkedProjectId !== null && $linkedProjectId !== '') {
            $this->state->projectId = $linkedProjectId;
        }
        $linkedClientId = $parsed->project?->clientId
            ?? (isset($parsed->routing['clientId']) ? trim((string) $parsed->routing['clientId']) : null);
        if ($linkedClientId !== null && $linkedClientId !== '') {
            $this->state->clientId = $linkedClientId;
        }

        $this->scopedProjectId = $this->state->projectId;

        return $parsed;
    }

    public function submitFormsContract(FormsContractSubmissionRequest $request): FormsContractsResponse
    {
        $this->assertScopedProjectAllowedForFormsPayload($request->toArray());
        $response = $this->post('/api/v1/plugin-onboarding/forms/contracts', $request->toArray());
        $parsed = FormsContractsResponse::fromResponseBody($response->body);
        if ($parsed->projectSourceId !== null && trim($parsed->projectSourceId) !== '') {
            $this->state->formsProjectSourceId = trim($parsed->projectSourceId);
        }
        $this->state->contractsVersion = $parsed->contractsVersion;
        $this->state->contractMappings = array_values(array_map(
            static fn ($mapping): array => method_exists($mapping, 'toArray')
                ? $mapping->toArray()
                : (array) $mapping,
            $parsed->contractMappings
        ));
        return $parsed;
    }

    public function fetchFormsContracts(string $projectId, string $platform): FormsContractsResponse
    {
        $this->assertScopedProjectIdMatches($projectId, 'forms contracts fetch');
        $request = new FormsContractsFetchRequest($platform, $projectId);
        $response = $this->post('/api/v1/plugin-onboarding/forms/contracts/fetch', $request->toArray());
        return FormsContractsResponse::fromResponseBody($response->body);
    }

    public function getLinkedProjectDeepLink(): ?LinkedProjectDeepLink
    {
        return $this->lastLinkResponse?->toDeepLink();
    }

    public function getState(): BurrowClientState
    {
        return BurrowClientState::fromArray($this->state->toArray());
    }

    public function getProjectId(): ?string
    {
        return $this->state->projectId;
    }

    public function getProjectSourceId(?string $channel = 'forms'): ?string
    {
        if ($channel !== 'forms') {
            return null;
        }

        return $this->state->formsProjectSourceId;
    }

    public function getBackfillRouting(string $channel): array
    {
        if ($channel !== 'forms') {
            throw new SdkPreflightException(
                'MISSING_PROJECT_SOURCE_ID',
                sprintf('Unsupported backfill channel "%s".', $channel),
                'Only forms channel is currently supported for SDK backfill routing.'
            );
        }
        if ($this->state->projectId === null || $this->state->projectId === '') {
            throw new SdkPreflightException(
                'MISSING_PROJECT_ID',
                'Cannot run forms backfill without a projectId.',
                'Run plugin onboarding link first so project context can be stored.'
            );
        }
        if ($this->state->formsProjectSourceId === null || $this->state->formsProjectSourceId === '') {
            throw new SdkPreflightException(
                'MISSING_PROJECT_SOURCE_ID',
                'Cannot run forms backfill without a projectSourceId.',
                'Sync forms contracts first so projectSourceId is persisted in SDK state.'
            );
        }

        $routing = [
            'projectId' => $this->state->projectId,
            'projectSourceId' => $this->state->formsProjectSourceId,
        ];
        if ($this->state->clientId !== null && $this->state->clientId !== '') {
            $routing['clientId'] = $this->state->clientId;
        }

        return $routing;
    }

    public static function isRetryableSdkError(\Throwable $error): bool
    {
        return SdkErrorClassifier::isRetryableSdkError($error);
    }

    public function publishEvent(array $event): HttpResponse
    {
        $normalizedEvent = EventEnvelopeBuilder::build($event, ['strictNames' => true]);
        $this->assertChannelProjectSourceId($normalizedEvent);
        $this->assertScopedProjectAllowedForEvent($normalizedEvent);
        return $this->post('/api/v1/events', $normalizedEvent);
    }

    public function backfillEvents(
        BackfillEventsRequest $request,
        ?BackfillOptions $options = null,
        ?callable $progressCallback = null
    ): BackfillEventsResult {
        $this->assertBackfillPreflight($request);
        $options ??= new BackfillOptions();
        $batchSize = min(100, max(1, $options->batchSize));
        $concurrency = max(1, $options->concurrency);

        $validated = $this->validateBackfillEvents($request->events);
        $validEvents = $validated['validEvents'];
        $validationRejections = $validated['validationRejections'];

        /** @var list<array<string,mixed>> $accepted */
        $accepted = [];
        /** @var list<array<string,mixed>> $rejected */
        $rejected = [];
        foreach ($validationRejections as $validationRejection) {
            $rejected[] = [
                'index' => $validationRejection['index'],
                'reason' => $validationRejection['reason'],
                'message' => $validationRejection['message'],
            ];
        }

        $chunks = array_chunk($validEvents, $batchSize);
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

            try {
            $responses = $this->submitBackfillWindowWithRetry(
                    eventChunks: $chunkWindow,
                    request: $request,
                    options: $options,
                    concurrency: $concurrency
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

            foreach ($responses as $response) {
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
        $validationRejectedCount = count($validationRejections);

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
            validationRejectedCount: $validationRejectedCount,
            validationRejections: $validationRejections,
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
            throw $this->normalizeApiError($path, $response);
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
                $payload = $this->buildBackfillPayload($events, $request);
                return $this->post('/api/v1/plugin-backfill/events', $payload, [200, 207]);
            } catch (\Throwable $exception) {
                if (!SdkErrorClassifier::isRetryableSdkError($exception) || $attempt >= $options->maxAttempts) {
                    throw $exception;
                }
                $response = $exception instanceof SdkApiException
                    ? new HttpResponse($exception->status, null, $exception->rawBody, $exception->headers)
                    : null;
                $this->sleepForBackfillRetry($attempt, $options, $response);
            }
        } while ($attempt < $options->maxAttempts);

        throw new \RuntimeException('Backfill chunk retries exhausted.');
    }

    /**
     * @param list<list<array<string,mixed>>> $eventChunks
     * @return list<HttpResponse>
     */
    private function submitBackfillWindowWithRetry(
        array $eventChunks,
        BackfillEventsRequest $request,
        BackfillOptions $options,
        int $concurrency
    ): array {
        if (
            $concurrency > 1
            && count($eventChunks) > 1
            && $this->transport instanceof ConcurrentHttpTransportInterface
        ) {
            return $this->submitBackfillWindowConcurrentlyWithRetry($eventChunks, $request, $options);
        }

        $responses = [];
        foreach ($eventChunks as $events) {
            $responses[] = $this->submitBackfillChunkWithRetry($events, $request, $options);
        }

        return $responses;
    }

    /**
     * @param list<list<array<string,mixed>>> $eventChunks
     * @return list<HttpResponse>
     */
    private function submitBackfillWindowConcurrentlyWithRetry(
        array $eventChunks,
        BackfillEventsRequest $request,
        BackfillOptions $options
    ): array {
        $attempt = 0;
        do {
            $attempt++;
            try {
                $url = rtrim($this->baseUrl, '/') . '/api/v1/plugin-backfill/events';
                $headers = ApiKeyAuthHeaderProvider::fromApiKey($this->apiKey);
                $requests = [];
                foreach ($eventChunks as $events) {
                    $payload = $this->buildBackfillPayload($events, $request);
                    $requests[] = [
                        'url' => $url,
                        'headers' => $headers,
                        'payload' => $payload,
                    ];
                }

                $responses = $this->transport->postConcurrent($requests);
                foreach ($responses as $response) {
                    if (!in_array($response->status, [200, 207], true)) {
                        throw $this->normalizeApiError('/api/v1/plugin-backfill/events', $response);
                    }
                }

                return $responses;
            } catch (\Throwable $exception) {
                if (!SdkErrorClassifier::isRetryableSdkError($exception) || $attempt >= $options->maxAttempts) {
                    throw $exception;
                }
                $response = $exception instanceof SdkApiException
                    ? new HttpResponse($exception->status, null, $exception->rawBody, $exception->headers)
                    : null;
                $this->sleepForBackfillRetry($attempt, $options, $response);
            }
        } while ($attempt < $options->maxAttempts);

        throw new \RuntimeException('Backfill concurrent window retries exhausted.');
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return array{
     *   validEvents:list<array<string,mixed>>,
     *   validationRejections:list<array{index:int,reason:string,message:string}>
     * }
     */
    private function validateBackfillEvents(array $events): array
    {
        $validEvents = [];
        $validationRejections = [];

        foreach ($events as $index => $event) {
            $timestamp = $event['timestamp'] ?? null;
            if (!is_string($timestamp) || trim($timestamp) === '') {
                $validationRejections[] = [
                    'index' => $index,
                    'reason' => 'missing_timestamp',
                    'message' => 'Backfill event is missing required timestamp.',
                ];
                continue;
            }

            $normalizedTimestamp = $this->normalizeTimestamp($timestamp);
            if ($normalizedTimestamp === null) {
                $validationRejections[] = [
                    'index' => $index,
                    'reason' => 'invalid_timestamp',
                    'message' => 'Backfill event timestamp is not a valid parseable date string.',
                ];
                continue;
            }

            $event['timestamp'] = $normalizedTimestamp;
            $channel = strtolower(trim((string) ($event['channel'] ?? '')));
            if (!in_array($channel, ['system', 'ecommerce'], true)) {
                $validEvents[] = $event;
                continue;
            }

            try {
                $normalizedEvent = EventEnvelopeBuilder::build($event, ['strictNames' => true]);
                $this->assertChannelProjectSourceId($normalizedEvent);
                $validEvents[] = $normalizedEvent;
            } catch (EventContractException|\InvalidArgumentException $exception) {
                $validationRejections[] = [
                    'index' => $index,
                    'reason' => 'invalid_contract',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return [
            'validEvents' => $validEvents,
            'validationRejections' => $validationRejections,
        ];
    }

    private function normalizeTimestamp(string $timestamp): ?string
    {
        try {
            $dateTime = new DateTimeImmutable($timestamp);
        } catch (\Exception) {
            return null;
        }

        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private function assertBackfillPreflight(BackfillEventsRequest $request): void
    {
        if ($request->channel !== 'forms') {
            return;
        }

        if ($this->state->ingestionKey === null || $this->state->ingestionKey === '') {
            throw new SdkPreflightException(
                'MISSING_INGESTION_KEY',
                'Cannot run forms backfill without an ingestion key.',
                'Run onboarding link and persist the returned ingestionKey before backfill.'
            );
        }

        $this->getBackfillRouting('forms');
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return array<string,mixed>
     */
    private function buildBackfillPayload(array $events, BackfillEventsRequest $request): array
    {
        $routing = null;
        if ($request->channel === 'forms') {
            $routing = $this->getBackfillRouting('forms');
        } elseif ($request->routing !== []) {
            $routing = [];
            foreach ($request->routing as $key => $value) {
                if (!is_string($key) || !is_scalar($value)) {
                    continue;
                }
                $trimmed = trim((string) $value);
                if ($trimmed === '') {
                    continue;
                }
                $routing[$key] = $trimmed;
            }
        }

        $normalizedEvents = [];
        foreach ($events as $event) {
            $next = $event;
            if (!isset($next['channel']) || !is_string($next['channel']) || trim($next['channel']) === '') {
                $next['channel'] = $request->channel ?? 'forms';
            }
            if ((!isset($next['event']) || !is_string($next['event']) || trim($next['event']) === '')
                && ($request->channel === 'forms' || $request->channel === null)
            ) {
                $next['event'] = 'forms.submission.received';
            }
            if ((!isset($next['source']) || !is_string($next['source']) || trim($next['source']) === '')
                && is_string($request->source)
                && trim($request->source) !== ''
            ) {
                $next['source'] = trim($request->source);
            }
            $normalizedEvents[] = $next;
        }

        $payload = [
            'events' => $normalizedEvents,
            'backfill' => $request->backfill->toArray(),
        ];
        if (is_array($routing) && $routing !== []) {
            $payload['routing'] = $routing;
        }

        return $payload;
    }

    private function normalizeApiError(string $path, HttpResponse $response): SdkApiException
    {
        $payload = is_array($response->body) ? $response->body : [];
        $errorNode = isset($payload['error']) && is_array($payload['error']) ? $payload['error'] : [];
        $topCode = isset($payload['code']) && is_string($payload['code']) ? trim($payload['code']) : null;
        $nestedCode = isset($errorNode['code']) && is_string($errorNode['code']) ? trim($errorNode['code']) : null;
        $code = $this->mapApiErrorCode($response->status, $topCode ?: $nestedCode, $payload, $errorNode);
        $message = (isset($errorNode['message']) && is_string($errorNode['message']) && trim($errorNode['message']) !== '')
            ? trim($errorNode['message'])
            : sprintf('Burrow endpoint %s returned status %d.', $path, $response->status);
        $retryable = $response->status === 429 || $response->status >= 500;
        $rejected = isset($payload['rejected']) && is_array($payload['rejected'])
            ? array_values(array_filter($payload['rejected'], static fn (mixed $row): bool => is_array($row)))
            : [];
        $apiError = [
            'code' => $code,
            'message' => $message,
            'hint' => isset($errorNode['hint']) && is_string($errorNode['hint']) ? $errorNode['hint'] : null,
            'required' => isset($errorNode['required']) && is_array($errorNode['required']) ? $errorNode['required'] : null,
            'details' => isset($errorNode['details']) && is_array($errorNode['details']) ? $errorNode['details'] : null,
        ];

        $this->logApiError($path, $response->status, $code, $rejected);

        return new SdkApiException(
            path: $path,
            status: $response->status,
            codeName: $code,
            message: $message,
            retryable: $retryable,
            rejected: $rejected,
            apiError: $apiError,
            rawBody: $response->raw,
            headers: $response->headers
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $errorNode
     */
    private function mapApiErrorCode(int $status, ?string $responseCode, array $payload, array $errorNode): string
    {
        if ($status === 401) {
            return 'INVALID_INGESTION_API_KEY';
        }
        if ($status === 400) {
            if (is_string($responseCode) && $responseCode !== '') {
                return $responseCode;
            }
            $message = strtolower(
                trim((string) ($errorNode['message'] ?? '')) . ' ' . trim((string) ($payload['message'] ?? ''))
            );
            if (str_contains($message, 'attribution') || str_contains($message, 'projectsourceid')) {
                return 'FORMS_BACKFILL_ATTRIBUTION_REQUIRED';
            }
            if (str_contains($message, 'no events')) {
                return 'NO_EVENTS_PROVIDED';
            }
            if (str_contains($message, 'json')) {
                return 'INVALID_JSON_BODY';
            }
        }

        return (is_string($responseCode) && $responseCode !== '') ? $responseCode : 'UNKNOWN_API_ERROR';
    }

    /**
     * @param list<array<string,mixed>> $rejected
     */
    private function logApiError(string $path, int $status, string $errorCode, array $rejected): void
    {
        if ($this->debugLogger === null) {
            return;
        }

        $rejectedReasons = [];
        foreach ($rejected as $row) {
            $reason = $row['reason'] ?? null;
            if (is_string($reason) && $reason !== '') {
                $rejectedReasons[] = $reason;
            }
        }

        ($this->debugLogger)([
            'endpoint' => $path,
            'status' => $status,
            'errorCode' => $errorCode,
            'rejectedReasons' => $rejectedReasons,
            'apiKeyPrefix' => $this->redactApiKey($this->apiKey),
        ]);
    }

    private function redactApiKey(string $apiKey): string
    {
        $trimmed = trim($apiKey);
        if (strlen($trimmed) <= 8) {
            return $trimmed . '***';
        }

        return substr($trimmed, 0, 8) . '***';
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

    /**
     * @param array<string,mixed> $event
     */
    private function assertScopedProjectAllowedForEvent(array $event): void
    {
        if ($this->scopedProjectId === null) {
            return;
        }

        $projectId = isset($event['projectId']) ? trim((string) $event['projectId']) : '';
        if ($projectId === '') {
            throw new \InvalidArgumentException('projectId is required when using a project-scoped ingestion key.');
        }

        if ($projectId !== $this->scopedProjectId) {
            throw new \InvalidArgumentException(sprintf(
                'projectId "%s" does not match scoped key project "%s".',
                $projectId,
                $this->scopedProjectId
            ));
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function assertScopedProjectAllowedForFormsPayload(array $payload): void
    {
        if ($this->scopedProjectId === null) {
            return;
        }

        $projectId = $this->extractProjectIdFromRouting($payload);
        if ($projectId === null) {
            throw new \InvalidArgumentException(
                'routing.projectId is required when using a project-scoped ingestion key.'
            );
        }

        $this->assertScopedProjectIdMatches($projectId, 'forms contracts');
    }

    private function assertScopedProjectIdMatches(string $projectId, string $operation): void
    {
        if ($this->scopedProjectId === null) {
            return;
        }

        if ($projectId !== $this->scopedProjectId) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot %s for project "%s" with scoped key for project "%s".',
                $operation,
                $projectId,
                $this->scopedProjectId
            ));
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function extractProjectIdFromRouting(array $payload): ?string
    {
        $routing = $payload['routing'] ?? null;
        if (!is_array($routing)) {
            return null;
        }

        $projectId = $routing['projectId'] ?? null;
        if (!is_string($projectId) || trim($projectId) === '') {
            return null;
        }

        return trim($projectId);
    }

    /**
     * @param array<string,mixed> $event
     */
    private function assertChannelProjectSourceId(array $event): void
    {
        $channel = strtolower(trim((string) ($event['channel'] ?? '')));
        if (!in_array($channel, ['system', 'ecommerce', 'forms'], true)) {
            return;
        }

        $projectId = trim((string) ($event['projectId'] ?? ''));
        if ($projectId === '') {
            throw new EventContractException(
                errorCode: 'MISSING_PROJECT_ID',
                message: 'projectId is required for Burrow event envelopes.'
            );
        }

        $projectSourceId = trim((string) ($event['projectSourceId'] ?? ''));
        if ($projectSourceId === '') {
            throw new EventContractException(
                errorCode: 'MISSING_PROJECT_SOURCE_ID_FOR_CHANNEL',
                message: sprintf('projectSourceId is required for channel "%s".', $channel)
            );
        }
    }
}
