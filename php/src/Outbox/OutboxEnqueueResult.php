<?php

declare(strict_types=1);

namespace Burrow\Sdk\Outbox;

final readonly class OutboxEnqueueResult
{
    public function __construct(
        public bool $deduped,
        public string $eventKey,
        public ?OutboxRecord $record = null
    ) {
    }
}
