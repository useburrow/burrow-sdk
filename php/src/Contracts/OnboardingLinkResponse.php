<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final readonly class OnboardingLinkResponse
{
    /**
     * @param array<string,mixed> $routing
     * @param array<string,mixed> $capabilities
     */
    public function __construct(
        public array $routing,
        public ?OnboardingIngestionKey $ingestionKey,
        public ?OnboardingLinkedProject $project,
        public array $capabilities = []
    ) {
    }

    /**
     * @param array<string,mixed>|null $body
     */
    public static function fromResponseBody(?array $body): self
    {
        $payload = $body ?? [];
        $routing = is_array($payload['routing'] ?? null) ? $payload['routing'] : [];
        $capabilities = is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : [];

        $ingestionKey = null;
        if (is_array($payload['ingestionKey'] ?? null)) {
            $parsed = OnboardingIngestionKey::fromArray($payload['ingestionKey']);
            if ($parsed->key !== '') {
                $ingestionKey = $parsed;
            }
        }

        $project = null;
        if (is_array($payload['project'] ?? null)) {
            $parsed = OnboardingLinkedProject::fromArray($payload['project']);
            if ($parsed->id !== '') {
                $project = $parsed;
            }
        }

        return new self(
            routing: $routing,
            ingestionKey: $ingestionKey,
            project: $project,
            capabilities: $capabilities
        );
    }

    public function toDeepLink(): ?LinkedProjectDeepLink
    {
        if ($this->project === null) {
            return null;
        }

        return new LinkedProjectDeepLink(
            path: $this->project->burrowProjectPath,
            url: $this->project->burrowProjectUrl
        );
    }
}
