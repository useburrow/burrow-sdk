<?php

declare(strict_types=1);

namespace Burrow\Sdk\Outbox;

use Burrow\Sdk\Client\BurrowClientInterface;
use Burrow\Sdk\Client\Exception\SdkApiException;
use Burrow\Sdk\Client\Exception\UnexpectedResponseStatusException;
use Burrow\Sdk\Transport\Exception\TransportFailureException;
use Throwable;

final class OutboxWorker
{
    public function __construct(
        private readonly OutboxStoreInterface $store,
        private readonly BurrowClientInterface $client,
        private readonly int $maxAttempts = 5,
        private readonly BackoffStrategyInterface $backoffStrategy = new ExponentialBackoffStrategy(),
        private readonly ?\Closure $logger = null
    ) {
    }

    public function runOnce(int $limit = 50): OutboxWorkerResult
    {
        $records = $this->store->pullPending($limit);
        $sentCount = 0;
        $retryingCount = 0;
        $failedCount = 0;

        foreach ($records as $record) {
            try {
                $this->client->publishEvent($record->payload);
                $this->store->markSent($record->id);
                $this->logTransition($record, OutboxStatus::SENT, null, null, true);
                $sentCount++;
                continue;
            } catch (TransportFailureException $exception) {
                if ($this->shouldRetry($record->attemptCount)) {
                    $delay = $this->backoffStrategy->delaySecondsForAttempt($record->attemptCount + 1);
                    $this->store->markRetrying($record->id, $exception->getMessage(), $delay);
                    $this->logTransition($record, OutboxStatus::RETRYING, $exception->getMessage(), null, true);
                    $retryingCount++;
                    continue;
                }

                $this->store->markFailed($record->id, $exception->getMessage());
                $this->logTransition($record, OutboxStatus::FAILED, $exception->getMessage(), null, false);
                $failedCount++;
                continue;
            } catch (UnexpectedResponseStatusException $exception) {
                if ($exception->isRetryable() && $this->shouldRetry($record->attemptCount)) {
                    $delay = $this->backoffStrategy->delaySecondsForAttempt($record->attemptCount + 1);
                    $this->store->markRetrying($record->id, $exception->getMessage(), $delay);
                    $this->logTransition($record, OutboxStatus::RETRYING, $exception->getMessage(), $exception->response->status, true);
                    $retryingCount++;
                    continue;
                }

                $this->store->markFailed($record->id, $exception->getMessage());
                $this->logTransition($record, OutboxStatus::FAILED, $exception->getMessage(), $exception->response->status, false);
                $failedCount++;
                continue;
            } catch (SdkApiException $exception) {
                if ($exception->retryable && $this->shouldRetry($record->attemptCount)) {
                    $delay = $this->backoffStrategy->delaySecondsForAttempt($record->attemptCount + 1);
                    $this->store->markRetrying($record->id, $exception->getMessage(), $delay);
                    $this->logTransition($record, OutboxStatus::RETRYING, $exception->getMessage(), $exception->status, true);
                    $retryingCount++;
                    continue;
                }

                $this->store->markFailed($record->id, $exception->getMessage());
                $this->logTransition($record, OutboxStatus::FAILED, $exception->getMessage(), $exception->status, false);
                $failedCount++;
                continue;
            } catch (Throwable $exception) {
                $this->store->markFailed($record->id, $exception->getMessage());
                $this->logTransition($record, OutboxStatus::FAILED, $exception->getMessage(), null, false);
                $failedCount++;
            }
        }

        return new OutboxWorkerResult(
            processedCount: count($records),
            sentCount: $sentCount,
            retryingCount: $retryingCount,
            failedCount: $failedCount
        );
    }

    private function shouldRetry(int $currentAttemptCount): bool
    {
        return ($currentAttemptCount + 1) < $this->maxAttempts;
    }

    private function logTransition(
        OutboxRecord $record,
        string $toStatus,
        ?string $message,
        ?int $httpStatus,
        bool $retryable
    ): void {
        if ($this->logger === null) {
            return;
        }

        ($this->logger)([
            'eventKey' => $record->eventKey,
            'eventKeyShort' => substr($record->eventKey, 0, 12),
            'fromStatus' => $record->status,
            'toStatus' => $toStatus,
            'attemptCount' => $record->attemptCount + 1,
            'message' => $message,
            'httpStatus' => $httpStatus,
            'retryable' => $retryable,
        ]);
    }
}
