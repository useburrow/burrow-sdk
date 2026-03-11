<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final readonly class OnboardingLinkedProject
{
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $slug,
        public ?string $clientId,
        public ?string $clientName,
        public ?string $clientSlug,
        public ?string $burrowProjectPath,
        public ?string $burrowProjectUrl
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            id: (string) ($payload['id'] ?? ''),
            name: isset($payload['name']) ? (string) $payload['name'] : null,
            slug: isset($payload['slug']) ? (string) $payload['slug'] : null,
            clientId: isset($payload['clientId']) ? (string) $payload['clientId'] : null,
            clientName: isset($payload['clientName']) ? (string) $payload['clientName'] : null,
            clientSlug: isset($payload['clientSlug']) ? (string) $payload['clientSlug'] : null,
            burrowProjectPath: isset($payload['burrowProjectPath']) ? (string) $payload['burrowProjectPath'] : null,
            burrowProjectUrl: isset($payload['burrowProjectUrl']) ? (string) $payload['burrowProjectUrl'] : null
        );
    }
}
