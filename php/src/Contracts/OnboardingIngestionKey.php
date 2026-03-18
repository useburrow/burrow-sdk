<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final readonly class OnboardingIngestionKey
{
    public function __construct(
        public string $key,
        public ?string $keyPrefix,
        public ?string $scope,
        public ?string $projectId
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            key: (string) ($payload['key'] ?? ''),
            keyPrefix: isset($payload['keyPrefix']) ? (string) $payload['keyPrefix'] : null,
            scope: isset($payload['scope']) ? strtolower((string) $payload['scope']) : null,
            projectId: isset($payload['projectId']) ? (string) $payload['projectId'] : null
        );
    }

    public function isProjectScoped(): bool
    {
        return $this->scope === 'project' && $this->projectId !== null && $this->projectId !== '';
    }
}
