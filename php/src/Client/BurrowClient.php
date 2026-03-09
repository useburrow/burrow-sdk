<?php

declare(strict_types=1);

namespace Burrow\Sdk\Client;

use Burrow\Sdk\Contracts\FormsContractSubmissionRequest;
use Burrow\Sdk\Contracts\OnboardingDiscoveryRequest;
use Burrow\Sdk\Contracts\OnboardingLinkRequest;
use Burrow\Sdk\Transport\ApiKeyAuthHeaderProvider;
use Burrow\Sdk\Transport\HttpTransportInterface;

final class BurrowClient implements BurrowClientInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly HttpTransportInterface $transport
    ) {
    }

    public function discover(OnboardingDiscoveryRequest $request): array
    {
        return $this->post('/api/v1/plugin-onboarding/discover', $request->toArray());
    }

    public function link(OnboardingLinkRequest $request): array
    {
        return $this->post('/api/v1/plugin-onboarding/link', $request->toArray());
    }

    public function submitFormsContract(FormsContractSubmissionRequest $request): array
    {
        return $this->post('/api/v1/plugin-onboarding/forms/contracts', $request->toArray());
    }

    public function publishEvent(array $event): array
    {
        return $this->post('/api/v1/events', $event);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int,body:array<string,mixed>|null,raw:string}
     */
    private function post(string $path, array $payload): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $headers = ApiKeyAuthHeaderProvider::fromApiKey($this->apiKey);
        return $this->transport->post($url, $headers, $payload);
    }
}
