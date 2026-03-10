<?php

declare(strict_types=1);

namespace Burrow\Sdk\Client;

final readonly class BackfillEventsResult
{
    /**
     * @param list<array<string,mixed>> $accepted
     * @param list<array<string,mixed>> $rejected
     */
    public function __construct(
        public array $accepted,
        public array $rejected,
        public int $requestedCount,
        public int $acceptedCount,
        public int $rejectedCount,
        public ?string $latestCursor
    ) {
    }
}
