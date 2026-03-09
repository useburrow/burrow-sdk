<?php

declare(strict_types=1);

namespace Burrow\Sdk\Outbox;

final class InMemoryOutboxStore implements OutboxStoreInterface
{
    /** @var array<string, OutboxRecord> */
    private array $records = [];

    public function enqueue(string $eventKey, array $payload): OutboxRecord
    {
        $id = bin2hex(random_bytes(8));
        $record = new OutboxRecord(
            id: $id,
            eventKey: $eventKey,
            status: OutboxStatus::PENDING,
            attemptCount: 0,
            payload: $payload
        );
        $this->records[$id] = $record;
        return $record;
    }

    public function pullPending(int $limit = 50): array
    {
        $rows = [];
        foreach ($this->records as $record) {
            if (
                $record->status === OutboxStatus::PENDING ||
                $record->status === OutboxStatus::RETRYING
            ) {
                $rows[] = $record;
            }
            if (count($rows) >= $limit) {
                break;
            }
        }
        return $rows;
    }

    public function markSent(string $id): void
    {
        $record = $this->records[$id] ?? null;
        if ($record === null) {
            return;
        }
        $this->records[$id] = new OutboxRecord(
            id: $record->id,
            eventKey: $record->eventKey,
            status: OutboxStatus::SENT,
            attemptCount: $record->attemptCount + 1,
            payload: $record->payload,
            lastError: null
        );
    }

    public function markRetrying(string $id, string $error): void
    {
        $this->updateWithStatus($id, OutboxStatus::RETRYING, $error);
    }

    public function markFailed(string $id, string $error): void
    {
        $this->updateWithStatus($id, OutboxStatus::FAILED, $error);
    }

    private function updateWithStatus(string $id, string $status, string $error): void
    {
        $record = $this->records[$id] ?? null;
        if ($record === null) {
            return;
        }
        $this->records[$id] = new OutboxRecord(
            id: $record->id,
            eventKey: $record->eventKey,
            status: $status,
            attemptCount: $record->attemptCount + 1,
            payload: $record->payload,
            lastError: $error
        );
    }
}
