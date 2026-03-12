<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final readonly class OnboardingLinkRequest
{
    /**
     * @param array<string, mixed> $site
     * @param array<string, mixed> $selection
     * @param array<string, mixed> $capabilities
     */
    public function __construct(
        public array $site,
        public array $selection,
        public ?string $platform = null,
        public array $capabilities = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'site' => $this->site,
            'selection' => $this->selection,
        ];

        if ($this->platform !== null && trim($this->platform) !== '') {
            $payload['platform'] = $this->platform;
        }

        if ($this->capabilities !== []) {
            $payload['capabilities'] = $this->capabilities;
        }

        return $payload;
    }
}
