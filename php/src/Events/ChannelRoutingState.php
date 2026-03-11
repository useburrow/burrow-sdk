<?php

declare(strict_types=1);

namespace Burrow\Sdk\Events;

final readonly class ChannelRoutingState
{
    /**
     * @param array{system?:string,forms?:string,ecommerce?:string} $projectSourceIds
     */
    public function __construct(
        public ?string $projectId,
        public array $projectSourceIds,
        public ?string $clientId = null
    ) {
    }
}
