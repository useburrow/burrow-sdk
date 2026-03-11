<?php

declare(strict_types=1);

namespace Burrow\Sdk\Events;

use Burrow\Sdk\Events\Exception\EventContractException;

final readonly class ChannelRoutingResolver
{
    public function __construct(private ChannelRoutingState $state)
    {
    }

    /**
     * @return array{projectId:string,projectSourceId:string,clientId:?string}
     */
    public function getRoutingForChannel(string $channel): array
    {
        $projectId = $this->state->projectId !== null ? trim($this->state->projectId) : '';
        if ($projectId === '') {
            throw new EventContractException(
                errorCode: 'MISSING_PROJECT_ID',
                message: 'projectId is required to route events.',
                remediation: 'Persist the linked projectId and pass it to ChannelRoutingState.'
            );
        }

        $channelKey = strtolower(trim($channel));
        $projectSourceId = $this->state->projectSourceIds[$channelKey] ?? null;
        if (!is_string($projectSourceId) || trim($projectSourceId) === '') {
            throw new EventContractException(
                errorCode: 'MISSING_PROJECT_SOURCE_ID_FOR_CHANNEL',
                message: sprintf('Missing projectSourceId for channel "%s".', $channelKey),
                remediation: 'Persist channel-specific source IDs from contracts/discovery before sending events.'
            );
        }

        return [
            'projectId' => $projectId,
            'projectSourceId' => trim($projectSourceId),
            'clientId' => $this->state->clientId,
        ];
    }
}
