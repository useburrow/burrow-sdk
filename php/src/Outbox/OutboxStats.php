<?php

declare(strict_types=1);

namespace Burrow\Sdk\Outbox;

final readonly class OutboxStats
{
    public function __construct(
        public int $pending,
        public int $retrying,
        public int $sent,
        public int $failed,
        public int $sentLedgerCount
    ) {
    }
}
