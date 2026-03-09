<?php

declare(strict_types=1);

namespace Burrow\Sdk\Client;

use Burrow\Sdk\Client\Exception\UnexpectedResponseStatusException;
use Burrow\Sdk\Contracts\FormsContractSubmissionRequest;
use Burrow\Sdk\Contracts\OnboardingDiscoveryRequest;
use Burrow\Sdk\Contracts\OnboardingLinkRequest;
use Burrow\Sdk\Transport\ApiKeyAuthHeaderProvider;
use Burrow\Sdk\Transport\HttpResponse;
use Burrow\Sdk\Transport\HttpTransportInterface;

final class BurrowClient implements BurrowClientInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly HttpTransportInterface $transport
    ) {
    }

    public function discover(OnboardingDiscoveryRequest $request): HttpResponse
    {
        return $this->post('/api/v1/plugin-onboarding/discover', $request->toArray());
    }

    public function link(OnboardingLinkRequest $request): HttpResponse
    {
        return $this->post('/api/v1/plugin-onboarding/link', $request->toArray());
    }

    public function submitFormsContract(FormsContractSubmissionRequest $request): HttpResponse
    {
        return $this->post('/api/v1/plugin-onboarding/forms/contracts', $request->toArray());
    }

    public function publishEvent(array $event): HttpResponse
    {
        return $this->post('/api/v1/events', $event);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function post(string $path, array $payload): HttpResponse
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $headers = ApiKeyAuthHeaderProvider::fromApiKey($this->apiKey);
        $response = $this->transport->post($url, $headers, $payload);

        if (!$response->isAccepted()) {
            throw new UnexpectedResponseStatusException($path, $response);
        }

        return $response;
    }
}
